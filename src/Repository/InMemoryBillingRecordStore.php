<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Repository;

use DateTimeImmutable;
use Nowo\VerifactuBundle\Model\AeatEnvironment;
use Nowo\VerifactuBundle\Model\AeatRecordResponse;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\StoredBillingRecord;
use Nowo\VerifactuBundle\Submission\BatchSplit;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Service\ResetInterface;

use function count;
use function in_array;
use function sprintf;

/**
 * Request-scoped record log for development, tests and demos (record_store.storage: memory).
 *
 * Cleared with the in-memory hash chain at the start of every main request and on kernel.reset. Not a legal record
 * store: use record_store.storage: doctrine or your own BillingRecordStoreInterface in production.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsAlias(id: self::SERVICE_NAME, public: true)]
final class InMemoryBillingRecordStore implements BillingRecordStoreInterface, SubmissionQueueInterface, ResetInterface
{
    public const SERVICE_NAME = 'nowo_verifactu.repository.in_memory_billing_record_store';

    /** @var array<string, array<int, StoredBillingRecord>> position - 1 => record */
    private array $records = [];

    /** @var array<string, array<int, array{string, DateTimeImmutable}>> issuer => position => [claim token, claimed at] */
    private array $claims = [];

    /**
     * Appends the record to the issuer log.
     */
    public function append(BillingRecord $record): int
    {
        $key      = $this->normalizeKey($record->issuerNif);
        $position = count($this->records[$key] ?? []) + 1;

        // @igor-ignore - Request-scoped: cleared by WorkerStateResetSubscriber on every main request and on kernel.reset.
        $this->records[$key][] = new StoredBillingRecord($position, $record);

        return $position;
    }

    /**
     * Returns the records of the issuer in chain order.
     *
     * @return list<StoredBillingRecord>
     */
    public function findByIssuer(string $issuerNif): iterable
    {
        return array_values($this->records[$this->normalizeKey($issuerNif)] ?? []);
    }

    /**
     * Records the outcome of the AEAT submission.
     */
    public function markSubmitted(string $issuerNif, string $hash, bool $accepted, ?string $reference = null): void
    {
        $key = $this->normalizeKey($issuerNif);
        if (!isset($this->records[$key])) {
            return;
        }

        $status = $accepted ? StoredBillingRecord::STATUS_ACCEPTED : StoredBillingRecord::STATUS_FAILED;
        // @igor-ignore - Request-scoped: cleared by WorkerStateResetSubscriber on every main request and on kernel.reset.
        $this->records[$key] = array_map(
            static fn (StoredBillingRecord $stored): StoredBillingRecord => strtoupper((string) $stored->record->hash) === strtoupper(trim($hash))
                ? new StoredBillingRecord($stored->position, $stored->record, $status, $reference)
                : $stored,
            $this->records[$key],
        );
    }

    public function hasSubmissionInFlight(string $issuerNif, DateTimeImmutable $now, int $claimTimeoutSeconds): bool
    {
        foreach ($this->claims[$this->normalizeKey($issuerNif)] ?? [] as [, $claimedAt]) {
            if ($claimedAt >= $this->cutoff($now, $claimTimeoutSeconds)) {
                return true;
            }
        }

        return false;
    }

    public function countPending(string $issuerNif, DateTimeImmutable $now, int $claimTimeoutSeconds): int
    {
        return count($this->waiting($issuerNif, $now, $claimTimeoutSeconds));
    }

    public function claimNext(string $issuerNif, int $limit, string $claimToken, DateTimeImmutable $now, int $claimTimeoutSeconds): array
    {
        $key     = $this->normalizeKey($issuerNif);
        $claimed = [];
        $seen    = [];
        $max     = max(1, $limit);
        foreach ($this->waiting($issuerNif, $now, $claimTimeoutSeconds) as $stored) {
            if ($claimed === []) {
                $max = BatchSplit::claimLimit($limit, $stored->submissionFailures);
            }
            $identity = AeatRecordResponse::key($stored->record->issuerNif, $stored->record->invoiceSeriesNumber, $stored->record->issueDate, $stored->record->recordType);
            if (count($claimed) >= $max || isset($seen[$identity]) || ($claimed !== [] && $stored->record->environment !== $claimed[0]->record->environment)) {
                break;
            }
            $seen[$identity] = true;
            $claimed[]       = $this->copy($stored, StoredBillingRecord::STATUS_SENDING);
        }
        foreach ($claimed as $stored) {
            $this->replace($key, $stored);
            // @igor-ignore - Request-scoped: cleared by WorkerStateResetSubscriber on every main request and on kernel.reset.
            $this->claims[$key][$stored->position] = [$claimToken, $now];
        }

        return $claimed;
    }

    public function markTransmitted(string $issuerNif, string $claimToken): int
    {
        $count = 0;
        foreach ($this->claimed($issuerNif, $claimToken) as $current) {
            $this->replace($this->normalizeKey($issuerNif), $this->copy($current, attempts: $current->submissionAttempts + 1));
            ++$count;
        }

        return $count;
    }

    public function recordOutcomes(string $issuerNif, string $claimToken, array $outcomes, DateTimeImmutable $now): array
    {
        $key     = $this->normalizeKey($issuerNif);
        $updated = [];
        foreach ($outcomes as $outcome) {
            $position = $outcome->stored->position;
            $current  = $this->find($key, $position);
            if (($this->claims[$key][$position][0] ?? null) !== $claimToken || !$current instanceof StoredBillingRecord) {
                continue;
            }
            $this->replace($key, new StoredBillingRecord(
                $position,
                $current->record,
                $outcome->storeStatus(),
                $outcome->status !== null ? $outcome->reference : $current->submissionReference,
                $current->submissionAttempts,
                $outcome->errorCode,
                $outcome->errorDescription,
                0,
            ));
            // @igor-ignore - Request-scoped: cleared by WorkerStateResetSubscriber on every main request and on kernel.reset.
            unset($this->claims[$key][$position]);
            $updated[] = strtoupper((string) $current->record->hash);
        }

        return $updated;
    }

    public function releaseClaim(string $issuerNif, string $claimToken, ?string $failure = null, bool $countFailure = false): int
    {
        $key      = $this->normalizeKey($issuerNif);
        $released = 0;
        foreach ($this->claimed($issuerNif, $claimToken) as $current) {
            $this->replace($key, $this->copy(
                $current,
                StoredBillingRecord::STATUS_PENDING,
                error: $failure ?? $current->submissionError,
                failures: $current->submissionFailures + ($countFailure ? 1 : 0),
            ));
            // @igor-ignore - Request-scoped: cleared by WorkerStateResetSubscriber on every main request and on kernel.reset.
            unset($this->claims[$key][$current->position]);
            ++$released;
        }

        return $released;
    }

    public function markFailed(string $issuerNif, string $claimToken, string $failure, DateTimeImmutable $now): array
    {
        $key    = $this->normalizeKey($issuerNif);
        $hashes = [];
        foreach ($this->claimed($issuerNif, $claimToken) as $current) {
            $this->replace($key, new StoredBillingRecord($current->position, $current->record, StoredBillingRecord::STATUS_FAILED, $current->submissionReference, $current->submissionAttempts, null, $failure, $current->submissionFailures + 1));
            // @igor-ignore - Request-scoped: cleared by WorkerStateResetSubscriber on every main request and on kernel.reset.
            unset($this->claims[$key][$current->position]);
            $hashes[] = strtoupper((string) $current->record->hash);
        }

        return $hashes;
    }

    public function requeueFailed(string $issuerNif): int
    {
        $key   = $this->normalizeKey($issuerNif);
        $count = 0;
        foreach ($this->records[$key] ?? [] as $stored) {
            if ($stored->submissionStatus === StoredBillingRecord::STATUS_FAILED) {
                $this->replace($key, $this->copy($stored, StoredBillingRecord::STATUS_PENDING, failures: 0));
                ++$count;
            }
        }

        return $count;
    }

    public function issuersWithPendingRecords(): array
    {
        $issuers = [];
        foreach ($this->records as $key => $records) {
            foreach ($records as $stored) {
                if ($stored->record->environment instanceof AeatEnvironment && in_array($stored->submissionStatus, [StoredBillingRecord::STATUS_PENDING, StoredBillingRecord::STATUS_SENDING], true)) {
                    $issuers[] = (string) $key;

                    break;
                }
            }
        }
        sort($issuers);

        return $issuers;
    }

    public function isInsideTransaction(): bool
    {
        return false;
    }

    /**
     * Waiting records with an AEAT environment (rows without one are never claimed, see SubmissionQueueInterface).
     *
     * @return list<StoredBillingRecord>
     */
    private function waiting(string $issuerNif, DateTimeImmutable $now, int $claimTimeoutSeconds): array
    {
        $key = $this->normalizeKey($issuerNif);

        return array_values(array_filter(
            $this->records[$key] ?? [],
            fn (StoredBillingRecord $stored): bool => $stored->record->environment instanceof AeatEnvironment && ($stored->submissionStatus === StoredBillingRecord::STATUS_PENDING
                || ($stored->submissionStatus === StoredBillingRecord::STATUS_SENDING && ($this->claims[$key][$stored->position][1] ?? $now) < $this->cutoff($now, $claimTimeoutSeconds))),
        ));
    }

    /**
     * The records currently held by the claim.
     *
     * @return list<StoredBillingRecord>
     */
    private function claimed(string $issuerNif, string $claimToken): array
    {
        $key     = $this->normalizeKey($issuerNif);
        $records = [];
        foreach ($this->claims[$key] ?? [] as $position => [$token]) {
            $current = $this->find($key, $position);
            if ($token === $claimToken && $current instanceof StoredBillingRecord && $current->submissionStatus === StoredBillingRecord::STATUS_SENDING) {
                $records[] = $current;
            }
        }

        return $records;
    }

    private function copy(StoredBillingRecord $stored, ?string $status = null, ?int $attempts = null, ?string $error = null, ?int $failures = null): StoredBillingRecord
    {
        return new StoredBillingRecord(
            $stored->position,
            $stored->record,
            $status ?? $stored->submissionStatus,
            $stored->submissionReference,
            $attempts ?? $stored->submissionAttempts,
            $stored->submissionErrorCode,
            $error ?? $stored->submissionError,
            $failures ?? $stored->submissionFailures,
        );
    }

    private function find(string $key, int $position): ?StoredBillingRecord
    {
        return $this->records[$key][$position - 1] ?? null;
    }

    private function replace(string $key, StoredBillingRecord $stored): void
    {
        // @igor-ignore - Request-scoped: cleared by WorkerStateResetSubscriber on every main request and on kernel.reset.
        $this->records[$key][$stored->position - 1] = $stored;
    }

    private function cutoff(DateTimeImmutable $now, int $claimTimeoutSeconds): DateTimeImmutable
    {
        return $now->modify(sprintf('-%d seconds', max(1, $claimTimeoutSeconds)));
    }

    /**
     * Drops every record (kernel.reset and the start of each main request).
     */
    public function clear(): void
    {
        $this->reset();
    }

    /**
     * Drops every record (kernel.reset / services_resetter; also called by clear()).
     */
    public function reset(): void
    {
        // @igor-ignore - Request-scoped store: this is the reset itself (kernel.reset and every main request).
        $this->records = [];
        // @igor-ignore - Request-scoped store: this is the reset itself (kernel.reset and every main request).
        $this->claims = [];
    }

    private function normalizeKey(string $issuerNif): string
    {
        return strtoupper(trim($issuerNif));
    }
}
