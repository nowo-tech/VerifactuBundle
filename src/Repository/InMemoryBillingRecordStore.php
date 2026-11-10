<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Repository;

use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\StoredBillingRecord;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Service\ResetInterface;

use function count;

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
final class InMemoryBillingRecordStore implements BillingRecordStoreInterface, ResetInterface
{
    public const SERVICE_NAME = 'nowo_verifactu.repository.in_memory_billing_record_store';

    /** @var array<string, list<StoredBillingRecord>> */
    private array $records = [];

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
        return $this->records[$this->normalizeKey($issuerNif)] ?? [];
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

    /**
     * Drops every record (kernel.reset and the start of each main request).
     */
    public function clear(): void
    {
        // @igor-ignore - Request-scoped: this is the reset itself.
        $this->records = [];
    }

    /**
     * Same as clear() (kernel.reset / services_resetter).
     */
    public function reset(): void
    {
        $this->clear();
    }

    private function normalizeKey(string $issuerNif): string
    {
        return strtoupper(trim($issuerNif));
    }
}
