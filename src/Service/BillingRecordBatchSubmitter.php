<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Service;

use DateTimeImmutable;
use LogicException;
use Nowo\VerifactuBundle\Client\BatchAeatSubmissionClientInterface;
use Nowo\VerifactuBundle\Client\SoapEnvelopeBuilder;
use Nowo\VerifactuBundle\Event\IssuerSubmissionPausedEvent;
use Nowo\VerifactuBundle\Event\IssuerSubmissionStalledEvent;
use Nowo\VerifactuBundle\Event\RecordSubmissionFailedEvent;
use Nowo\VerifactuBundle\Event\RecordSubmittedEvent;
use Nowo\VerifactuBundle\Event\VerifactuEvents;
use Nowo\VerifactuBundle\Model\AeatBatchResponse;
use Nowo\VerifactuBundle\Model\AeatRecordResponse;
use Nowo\VerifactuBundle\Model\BatchSubmissionResult;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordSubmissionOutcome;
use Nowo\VerifactuBundle\Model\RecordSubmissionStatus;
use Nowo\VerifactuBundle\Model\StoredBillingRecord;
use Nowo\VerifactuBundle\Repository\HashChainRepositoryInterface;
use Nowo\VerifactuBundle\Repository\LockableHashChainRepositoryInterface;
use Nowo\VerifactuBundle\Repository\SubmissionQueueInterface;
use Nowo\VerifactuBundle\Submission\AeatFaultClassifier;
use Nowo\VerifactuBundle\Submission\BatchSplit;
use Nowo\VerifactuBundle\Submission\DuplicateConfirmationInterface;
use Nowo\VerifactuBundle\Submission\FlowControlInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function array_filter;
use function array_map;
use function array_values;
use function bin2hex;
use function count;
use function implode;
use function in_array;
use function max;
use function min;
use function random_bytes;
use function sprintf;
use function strtoupper;
use function trim;

use const DATE_ATOM;

/**
 * Sends the stored records of an issuer to the AEAT in batches (V7): up to 1000 records per SuministroLR request, in
 * chain order, honouring the AEAT wait time, storing each record's answer and dispatching one event per record after
 * that answer is committed.
 *
 * Chain integrity: records are chained (BillingRecordProcessor::process() / processBatch()) and stored before they are
 * sent; sending never chains, re-chains or reorders anything. A record the AEAT rejects stays in the chain (it was
 * generated and sent); it is corrected with a NEW record chained after the last one, with Subsanacion S and
 * RechazoPrevio X (Servicios web v1.0.3 §9.1.3, p. 60 – 61). The chain head therefore always points at a stored record
 * that is sent or waiting to be sent.
 *
 * Flow control (Orden HAC/1177/2024 art. 16.2 c; Servicios web v1.0.3 §6.4.4.1): the next submission waits the
 * TiempoEsperaEnvio of the previous answer, unless it carries the record-design limit of 1000 records
 * (SoapEnvelopeBuilder::MAX_RECORDS_PER_SUBMISSION); a smaller `batch_size` never skips the wait. With the `global`
 * scope the check and a reservation of the slot run under one lock shared by every issuer.
 *
 * Concurrency and retries: the next batch is claimed inside the issuer's chain lock (one batch in flight per issuer, in
 * chain order); the HTTP request runs outside any transaction; an attempt is counted right before the request is
 * transmitted; the answers are stored in one transaction and only then are events dispatched. A request without an
 * answer puts the batch back to pending. Only a SOAP `Client` fault counts against the records: the next batch is halved
 * until the record at fault goes alone; after `max_attempts` refusals on its own it is held and the next record goes
 * alone. An answered probe marks the held record `failed` (RECORD_SUBMISSION_FAILED) and the queue flows; a refused
 * probe means an issuer-wide error: nothing is failed and the issuer is paused (ISSUER_SUBMISSION_PAUSED). A claim
 * older than the claim timeout (the process died) is claimed again.
 *
 * Duplicates: the AEAT "duplicate" answer (RegistroDuplicado) has no Huella, so it does not prove that the record the
 * AEAT holds is this one. It is mapped to a registered state only when a DuplicateConfirmationInterface confirms it
 * (same Huella). Otherwise a record transmitted before is stored as `unconfirmed` (never reported as accepted) and a
 * record never transmitted before is `rejected` (another record used the invoice id, FAQ v1.3 §6).
 *
 * Stateless (worker mode, FRANKENPHP kernel reset off): every call reads the queue and the flow control state again.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsAlias(id: self::SERVICE_NAME, public: true)]
final class BillingRecordBatchSubmitter
{
    public const SERVICE_NAME = 'nowo_verifactu.service.batch_submitter';

    /** Flow control key and lock key shared by every issuer (submission.flow_control_scope: global). */
    public const GLOBAL_FLOW_CONTROL_KEY = '*';

    /** Flow control key prefix of an issuer paused by the circuit breaker. */
    public const PAUSE_KEY_PREFIX = 'pause|';

    /** Flow control key prefix of the last ISSUER_SUBMISSION_STALLED alert of an issuer (one per pause_seconds). */
    public const STALLED_KEY_PREFIX = 'stalled|';

    /** Flow control key prefix of the Messenger wake-up pending for a paused issuer (one per pause, LJD3-L2). */
    public const WAKE_KEY_PREFIX = 'wake|';

    /** Flow control key prefix set by a request without any answer (the next one alerts ISSUER_SUBMISSION_STALLED). */
    public const NO_ANSWER_KEY_PREFIX = 'noanswer|';

    /** Suspects held before the last probe goes to the newest waiting record. */
    public const MAX_SUSPECTS = 3;

    private const MODE_BATCH        = 'batch';
    private const MODE_PROBE        = 'probe';
    private const MODE_PROBE_NEWEST = 'probe_newest';
    private const MODE_CONFIRM      = 'confirm';
    private const MODE_LONE         = 'lone';

    /**
     * @param int $batchSize records per request, 1 – 1000 (AEAT Servicios web v1.0.3 p. 10)
     * @param int $claimTimeout seconds after which a claim is stale and its records are claimed again
     * @param int $defaultWait seconds to wait when the AEAT gave no TiempoEsperaEnvio (initially 60, art. 16.2)
     * @param string $flowControlScope issuer (one wait per issuer NIF) or global (one wait for every issuer)
     * @param int $maxAttempts refusals of a record sent on its own before it is held (then failed or the issuer paused)
     * @param int $pauseSeconds how long an issuer stays paused after an issuer-wide refusal (circuit breaker); the pause
     *                          and the alert de-duplication live in the flow control store: a submitter built without one
     *                          never pauses
     */
    public function __construct(
        private readonly SubmissionQueueInterface $queue,
        private readonly BatchAeatSubmissionClientInterface $client,
        private readonly HashChainRepositoryInterface $hashChainRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ?FlowControlInterface $flowControl = null,
        private readonly ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly int $batchSize = SoapEnvelopeBuilder::MAX_RECORDS_PER_SUBMISSION,
        private readonly int $claimTimeout = 300,
        private readonly int $defaultWait = 60,
        private readonly string $flowControlScope = 'issuer',
        private readonly ?DuplicateConfirmationInterface $duplicateConfirmation = null,
        private readonly int $maxAttempts = 5,
        private readonly int $pauseSeconds = 3600,
        private readonly AeatFaultClassifier $faultClassifier = new AeatFaultClassifier(),
    ) {
    }

    /**
     * Sends the next batch of the issuer's waiting records, if the AEAT flow control allows it.
     *
     * Must run outside any transaction on the record store's connection (a Messenger handler, a command, a cron): the
     * answers are committed by the submitter itself before the AFTER_RECORD_SUBMISSION events.
     */
    public function submitPending(string $issuerNif): BatchSubmissionResult
    {
        if ($this->queue->isInsideTransaction()) {
            throw new LogicException('BillingRecordBatchSubmitter must run outside a database transaction: it commits each AEAT answer before dispatching its events. Run it from a Messenger handler, a command or after your transaction commits.');
        }

        $issuerNif = strtoupper(trim($issuerNif));
        $batchSize = $this->batchSize();
        $now       = $this->now();
        $global    = $this->flowControlScope === 'global';
        $flowKey   = $global ? self::GLOBAL_FLOW_CONTROL_KEY : $issuerNif;
        $token     = bin2hex(random_bytes(16));

        // Claimed under the issuer's chain lock: one batch in flight per issuer, in chain order, and the flow control
        // check serialised with the previous submission (it registers the wait before it releases its records).
        $claim = function () use ($issuerNif, $batchSize, $now, $flowKey, $token, $global): BatchSubmissionResult|array {
            if ($this->queue->hasSubmissionInFlight($issuerNif, $now, $this->claimTimeout)) {
                return new BatchSubmissionResult($issuerNif, BatchSubmissionResult::STATE_IN_FLIGHT);
            }
            $waiting  = $this->queue->countPending($issuerNif, $now, $this->claimTimeout);
            $suspects = $this->queue->suspects($issuerNif);
            if ($waiting === 0 && $suspects === []) {
                return new BatchSubmissionResult($issuerNif, BatchSubmissionResult::STATE_IDLE);
            }
            $pausedUntil = $this->flowControl?->nextSubmissionAt(self::PAUSE_KEY_PREFIX . $issuerNif);
            if ($pausedUntil instanceof DateTimeImmutable && $pausedUntil > $now) {
                return new BatchSubmissionResult($issuerNif, BatchSubmissionResult::STATE_PAUSED, nextSubmissionAt: $pausedUntil, remaining: $waiting);
            }
            $next     = $this->flowControl?->nextSubmissionAt($flowKey);
            $mustWait = $next instanceof DateTimeImmutable && $next > $now;
            // Art. 16.2 c: only a submission carrying the record-design limit (1000 records) may skip the wait; a
            // single-record request of the circuit breaker always waits.
            if ($mustWait && ($suspects !== [] || $batchSize < SoapEnvelopeBuilder::MAX_RECORDS_PER_SUBMISSION || $waiting < SoapEnvelopeBuilder::MAX_RECORDS_PER_SUBMISSION)) {
                return new BatchSubmissionResult($issuerNif, BatchSubmissionResult::STATE_THROTTLED, nextSubmissionAt: $next, remaining: $waiting);
            }

            [$mode, $claimed] = $this->claimFor($issuerNif, $suspects, $waiting, $batchSize, $token, $now);
            if ($mode === self::MODE_BATCH && $mustWait && $claimed !== [] && count($claimed) < SoapEnvelopeBuilder::MAX_RECORDS_PER_SUBMISSION) {
                // The claim stopped short of 1000 records (an environment change, a second record of one invoice, a
                // split batch): it is not a full submission, so it waits like any other.
                $this->queue->releaseClaim($issuerNif, $token);

                return new BatchSubmissionResult($issuerNif, BatchSubmissionResult::STATE_THROTTLED, nextSubmissionAt: $next, remaining: $waiting);
            }
            if ($global && $claimed !== []) {
                // Reserve the shared slot before the global lock is released: another issuer checking now must wait.
                $this->flowControl?->registerSubmission($flowKey, $now, $this->defaultWait);
            }

            return ['claimed' => $claimed, 'mode' => $mode];
        };
        $outcome = $this->underLocks($issuerNif, $global, $claim);
        if ($outcome instanceof BatchSubmissionResult) {
            return $outcome;
        }
        ['claimed' => $claimed, 'mode' => $mode] = $outcome;
        if ($claimed === []) {
            return new BatchSubmissionResult($issuerNif, BatchSubmissionResult::STATE_IDLE);
        }

        $transmitted    = false;
        $beforeTransmit = function () use ($issuerNif, $token, &$transmitted): void {
            // The request is about to leave the process: from now on it may reach the AEAT, so it is an attempt.
            $transmitted = true;
            $this->queue->markTransmitted($issuerNif, $token);
        };
        try {
            $response = $this->client->submitBatch(array_map(static fn (StoredBillingRecord $stored): BillingRecord => $stored->record, $claimed), $beforeTransmit);
        } catch (Throwable $exception) {
            // No request left the process, or its answer is unknown: the records go back as they were, untouched.
            $this->putBack($issuerNif, $token, $mode, $exception::class);

            throw $exception;
        }
        if ($transmitted) {
            $claimed = array_map(static fn (StoredBillingRecord $stored): StoredBillingRecord => new StoredBillingRecord(
                $stored->position,
                $stored->record,
                $stored->submissionStatus,
                $stored->submissionReference,
                $stored->submissionAttempts + 1,
                $stored->submissionErrorCode,
                $stored->submissionError,
                $stored->submissionFailures,
            ), $claimed);
        }

        // Registered before the records are released, so the next claim (under the lock) sees the new wait.
        $wait = $response->waitSeconds ?? $this->defaultWait;
        $next = $now->modify(sprintf('+%d seconds', max(0, $wait)));
        $this->flowControl?->registerSubmission($flowKey, $now, $wait);

        if ($response->isFailure()) {
            return $this->failed($issuerNif, $token, $mode, $claimed, $response, $batchSize, $now, $next);
        }

        $this->clearNoAnswer($issuerNif, $now);
        $outcomes = array_map(fn (StoredBillingRecord $stored): RecordSubmissionOutcome => $this->outcome($stored, $response), $claimed);
        $updated  = $this->queue->recordOutcomes($issuerNif, $token, $outcomes, $now);
        if (in_array($mode, [self::MODE_PROBE, self::MODE_PROBE_NEWEST, self::MODE_LONE], true)) {
            // A probe, or a lone suspect, was answered: the AEAT accepts this issuer's requests. Each other suspect is
            // sent alone once more (LJD4-L1: an answered lone suspect confirms the others too).
            $this->queue->confirmSuspects($issuerNif);
        }

        // After the commit of the answers: one event per record whose answer was stored by this submission.
        $eventErrors = [];
        foreach ($outcomes as $outcome) {
            if ($outcome->status === null || !in_array(strtoupper((string) $outcome->stored->record->hash), $updated, true)) {
                continue;
            }
            $this->dispatch(new RecordSubmittedEvent($outcome, $response), VerifactuEvents::AFTER_RECORD_SUBMISSION, $eventErrors);
        }

        return new BatchSubmissionResult(
            $issuerNif,
            BatchSubmissionResult::STATE_SENT,
            $outcomes,
            $response,
            $next,
            $this->queue->countPending($issuerNif, $now, $this->claimTimeout),
            $eventErrors,
        );
    }

    /**
     * Issuers with records waiting to be sent (for a command or a scheduler).
     *
     * @return list<string>
     */
    public function issuersWithPendingRecords(): array
    {
        return $this->queue->issuersWithPendingRecords();
    }

    /**
     * Puts the records this submitter failed for the issuer back to pending (after the cause of the AEAT refusal was
     * fixed). Records stored as failed by process(..., submitToAeat: true) stay failed.
     */
    public function requeueFailed(string $issuerNif): int
    {
        $issuerNif = strtoupper(trim($issuerNif));

        return $this->underIssuerLock($issuerNif, fn (): int => $this->queue->requeueFailed($issuerNif));
    }

    /**
     * The issuer's suspects (held, confirming), in chain order.
     *
     * @return list<StoredBillingRecord>
     */
    public function suspects(string $issuerNif): array
    {
        return $this->queue->suspects(strtoupper(trim($issuerNif)));
    }

    /**
     * The issuer's records that need a person: unconfirmed duplicates, suspects (held, confirming) and failed records, in
     * chain order.
     *
     * @return list<StoredBillingRecord>
     */
    public function recordsToReview(string $issuerNif): array
    {
        $review = [];
        foreach ($this->queue->findByIssuer(strtoupper(trim($issuerNif))) as $stored) {
            if (in_array($stored->submissionStatus, [StoredBillingRecord::STATUS_UNCONFIRMED, StoredBillingRecord::STATUS_HELD, StoredBillingRecord::STATUS_CONFIRMING, StoredBillingRecord::STATUS_FAILED], true)) {
                $review[] = $stored;
            }
        }

        return $review;
    }

    /**
     * Marks one pending, held or confirming record of the issuer failed by hand (an operator who knows the record is
     * wrong, e.g. a run of bad records the AEAT keeps refusing) and dispatches RECORD_SUBMISSION_FAILED after the
     * commit. Returns false when the record is in no such state. `--retry-failed` puts it back.
     *
     * Runs under the issuer's chain lock, like a claim (LJD3-L3): a record is never failed between the read and the
     * update of a claim, so it is never sent while stored failed.
     */
    public function failRecord(string $issuerNif, int $position, string $reason = 'Marked failed by hand.'): bool
    {
        $issuerNif = strtoupper(trim($issuerNif));
        $now       = $this->now();
        $stored    = $this->underIssuerLock($issuerNif, fn (): ?StoredBillingRecord => $this->queue->failRecord($issuerNif, $position, $reason, $now));
        if (!$stored instanceof StoredBillingRecord) {
            return false;
        }
        $eventErrors = [];
        $this->dispatch(new RecordSubmissionFailedEvent($issuerNif, $position, strtoupper((string) $stored->record->hash), $reason, $stored, null), VerifactuEvents::RECORD_SUBMISSION_FAILED, $eventErrors);

        return true;
    }

    /**
     * Whether a Messenger message that found the issuer paused until $until should be dispatched again at the end of
     * the pause (LJD3-L2, LJD4-I1). Returns the wake-up token to carry in the rescheduled message (the Unix time of the
     * pause end it is registered for), or null to drop the message:
     *
     * - no wake-up pending for this pause: it is registered in the flow control store (`wake|<NIF>`) and its token
     *   returned (the first message);
     * - a wake-up pending: only the message carrying its token ($token) keeps it (it may run a little before the pause
     *   ends); every other message, a superseded wake-up message included, is dropped.
     *
     * One message per issuer stays alive during a pause. A submitter without a flow control store never pauses, so it
     * always returns the token.
     */
    public function scheduleWakeUp(string $issuerNif, DateTimeImmutable $until, ?int $token = null): ?int
    {
        $flowControl = $this->flowControl;
        if (!$flowControl instanceof FlowControlInterface) {
            return $until->getTimestamp();
        }
        $issuerNif = strtoupper(trim($issuerNif));
        $key       = self::WAKE_KEY_PREFIX . $issuerNif;

        return $this->underIssuerLock($issuerNif, function () use ($flowControl, $key, $until, $token): ?int {
            $pending = $flowControl->nextSubmissionAt($key);
            if ($pending instanceof DateTimeImmutable && $pending >= $until) {
                return $token === $pending->getTimestamp() ? $token : null;
            }
            $now = $this->now();
            // @igor-ignore - Writes the shared flow-control store (cache / DB), meant to outlive the request; no service state.
            $flowControl->registerSubmission($key, $now, max(1, $until->getTimestamp() - $now->getTimestamp()));

            return $until->getTimestamp();
        });
    }

    /**
     * Resolves an `unconfirmed` record after checking it at the AEAT (ConsultaFactuSistemaFacturacion): accepted or
     * accepted with errors (give the CSV) when the AEAT holds this record (same Huella), rejected otherwise. Dispatches
     * nothing; returns false when the record is not unconfirmed.
     */
    public function resolveUnconfirmed(string $issuerNif, int $position, RecordSubmissionStatus $status, ?string $reference = null): bool
    {
        if ($status === RecordSubmissionStatus::Unconfirmed) {
            throw new LogicException('An unconfirmed record is resolved as accepted, accepted_errors or rejected.');
        }

        return $this->queue->resolveUnconfirmed(strtoupper(trim($issuerNif)), $position, $status, $reference, $this->now());
    }

    /**
     * Refusals of the request as a whole a record can take before it is marked failed: enough to split a full batch
     * down to that record alone (one halving per refusal), plus `max_attempts` refusals on its own.
     */
    public function failureLimit(?int $batchSize = null): int
    {
        return BatchSplit::halvings($batchSize ?? $this->batchSize()) + max(1, $this->maxAttempts);
    }

    /**
     * @param callable(): (array{claimed: list<StoredBillingRecord>, mode: string}|BatchSubmissionResult) $claim
     *
     * @return array{claimed: list<StoredBillingRecord>, mode: string}|BatchSubmissionResult
     */
    private function underLocks(string $issuerNif, bool $global, callable $claim): BatchSubmissionResult|array
    {
        if (!$this->hashChainRepository instanceof LockableHashChainRepositoryInterface) {
            return $claim();
        }
        $repository = $this->hashChainRepository;
        $issuer     = static fn (): BatchSubmissionResult|array => $repository->withChainLock($issuerNif, static fn (): BatchSubmissionResult|array => $claim());

        // Global scope: every issuer's check-and-reserve runs under one more lock, always taken first (no lock cycle
        // with the chaining processes, which only take the issuer lock).
        return $global
            ? $repository->withChainLock(self::GLOBAL_FLOW_CONTROL_KEY, static fn (): BatchSubmissionResult|array => $issuer())
            : $issuer();
    }

    /**
     * Runs $operation under the issuer's chain lock when the repository is lockable (the lock the claims take).
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function underIssuerLock(string $issuerNif, callable $operation): mixed
    {
        if (!$this->hashChainRepository instanceof LockableHashChainRepositoryInterface) {
            return $operation();
        }

        return $this->hashChainRepository->withChainLock($issuerNif, static fn (): mixed => $operation());
    }

    /**
     * What to send next (called under the issuer lock): a normal batch, or one record alone for the circuit breaker.
     *
     * - a confirming suspect (a probe was answered): that suspect alone, once more;
     * - held suspects and nothing else waiting: one suspect alone (it cannot be probed), rotating (LJD4-L1): the one
     *   refused the fewest times, the newest on a tie; answered, the others are confirmed like after a probe;
     * - fewer than MAX_SUSPECTS held: the next waiting record alone (a probe, in chain order);
     * - MAX_SUSPECTS held: the NEWEST waiting record alone (the last probe, away from a run of bad records); a refused
     *   newest probe is held too, so the next one goes to the record before it (LJD3-L1);
     * - no suspect: the next batch.
     *
     * @param list<StoredBillingRecord> $suspects
     *
     * @return array{string, list<StoredBillingRecord>}
     */
    private function claimFor(string $issuerNif, array $suspects, int $waiting, int $batchSize, string $token, DateTimeImmutable $now): array
    {
        if ($suspects === []) {
            return [self::MODE_BATCH, $this->queue->claimNext($issuerNif, $batchSize, $token, $now, $this->claimTimeout)];
        }
        $confirming        = array_values(array_filter($suspects, static fn (StoredBillingRecord $s): bool => $s->submissionStatus === StoredBillingRecord::STATUS_CONFIRMING));
        $held              = array_values(array_filter($suspects, static fn (StoredBillingRecord $s): bool => $s->submissionStatus === StoredBillingRecord::STATUS_HELD));
        [$mode, $position] = match (true) {
            $confirming !== []                 => [self::MODE_CONFIRM, $confirming[0]->position],
            $waiting === 0                     => [self::MODE_LONE, $this->loneSuspect($held)],
            count($held) >= self::MAX_SUSPECTS => [self::MODE_PROBE_NEWEST, $this->queue->newestWaitingPosition($issuerNif, $now, $this->claimTimeout)],
            default                            => [self::MODE_PROBE, null],
        };
        if ($mode === self::MODE_PROBE) {
            return [$mode, $this->queue->claimNext($issuerNif, 1, $token, $now, $this->claimTimeout)];
        }
        $record = $position === null ? null : $this->queue->claimRecord($issuerNif, $position, $token, $now, $this->claimTimeout);

        return [$mode, $record instanceof StoredBillingRecord ? [$record] : []];
    }

    /**
     * The held suspect to send alone when nothing else is waiting: the one refused the fewest times, the newest on a
     * tie, so a bad record never keeps the others from being tried (each lone refusal counts one more failure).
     *
     * @param list<StoredBillingRecord> $held never empty here (no confirming suspect, so at least one is held)
     */
    private function loneSuspect(array $held): int
    {
        $best = $held[0];
        foreach ($held as $suspect) {
            if ($suspect->submissionFailures < $best->submissionFailures || ($suspect->submissionFailures === $best->submissionFailures && $suspect->position > $best->position)) {
                $best = $suspect;
            }
        }

        return $best->position;
    }

    /**
     * Ends a claim without an answer, leaving every record as it was before the claim.
     */
    private function putBack(string $issuerNif, string $token, string $mode, ?string $failure): void
    {
        match ($mode) {
            self::MODE_CONFIRM => $this->queue->parkClaim($issuerNif, $token, StoredBillingRecord::STATUS_CONFIRMING, $failure),
            self::MODE_LONE    => $this->queue->parkClaim($issuerNif, $token, StoredBillingRecord::STATUS_HELD, $failure),
            default            => $this->queue->releaseClaim($issuerNif, $token, $failure),
        };
    }

    /**
     * A request without a per-record answer.
     *
     * Only a SOAP `Client` fault is about what the message carries (AeatBatchResponse::isRecordRefusal()); its Codigo
     * decides who it is about (AeatFaultClassifier):
     *
     * - ISSUER (a header, certificate or authorisation error): nothing is counted, the issuer is paused at once
     *   (ISSUER_SUBMISSION_PAUSED, reason issuer_refused);
     * - RECORD (schema, format): counted, the batch is split; a record refused on its own `max_attempts` times, or a
     *   probe or a suspect refused that way, is marked failed (RECORD_SUBMISSION_FAILED);
     * - UNKNOWN: counted, the batch is split; a record refused on its own `max_attempts` times becomes a suspect
     *   (`held`) and other records go alone as probes: the next one, the one after, then the newest waiting one. An
     *   answered probe turns the suspects into `confirming`: each is sent alone once more and marked failed only if it
     *   is refused again (a short issuer-wide error never fails a healthy record). When MAX_SUSPECTS suspects and the
     *   newest record are all refused, the error is issuer-wide (or the newest record is bad too): the issuer is paused
     *   (reason issuer_refused), the suspects stay held, listed by `--list`, and the refused newest record is held too,
     *   so the next probe, after the pause, goes to the record before it. A bad record at the tail of the queue thus
     *   delays the healthy records by one pause and never blocks them; while an issuer-wide error lasts, one more record
     *   is held per pause (each confirmed alone once the AEAT answers again). Suspects with nothing else waiting pause
     *   the issuer too (reason awaiting_probe); after each pause one of them goes alone, rotating (the fewest refusals,
     *   the newest on a tie), and an answered one confirms the others like a probe (LJD4-L1).
     *
     * Anything else (transport, Server fault, HTTP or non-SOAP error) counts nothing; when the issuer's previous request
     * got no answer either (no answer in between), ISSUER_SUBMISSION_STALLED is dispatched, at most once per
     * `pause_seconds`.
     *
     * @param list<StoredBillingRecord> $claimed
     */
    private function failed(string $issuerNif, string $token, string $mode, array $claimed, AeatBatchResponse $response, int $batchSize, DateTimeImmutable $now, DateTimeImmutable $next): BatchSubmissionResult
    {
        $failure = (string) $response->failure;
        if (!$response->isRecordRefusal()) {
            $this->putBack($issuerNif, $token, $mode, $failure);
            $this->logger?->warning('The AEAT submission of {count} Veri*Factu records got no answer; they stay pending.', ['count' => count($claimed)]);
            $eventErrors = [];
            if ($this->noAnswerBefore($issuerNif, $now) && $this->alertDue(self::STALLED_KEY_PREFIX . $issuerNif, $now)) {
                // The previous request of the issuer got no answer either: the submission is stalled.
                $this->dispatch(new IssuerSubmissionStalledEvent($issuerNif, $failure, $response->httpStatus, $response), VerifactuEvents::ISSUER_SUBMISSION_STALLED, $eventErrors);
            }

            return new BatchSubmissionResult($issuerNif, BatchSubmissionResult::STATE_FAILED, [], $response, $next, $this->queue->countPending($issuerNif, $now, $this->claimTimeout), $eventErrors);
        }

        $this->clearNoAnswer($issuerNif, $now);
        $class = $this->faultClassifier->classify($response);
        if ($class === AeatFaultClassifier::ISSUER) {
            $this->putBack($issuerNif, $token, $mode, $failure);

            return $this->pause($issuerNif, IssuerSubmissionPausedEvent::REASON_ISSUER_REFUSED, $failure, $response, $now);
        }

        $solo    = count($claimed) === 1;
        $atLimit = $solo && $claimed[0]->submissionFailures + 1 >= $this->failureLimit($batchSize);
        $failNow = match ($mode) {
            self::MODE_CONFIRM                        => true,
            self::MODE_PROBE, self::MODE_PROBE_NEWEST => $class === AeatFaultClassifier::RECORD,
            self::MODE_LONE                           => false,
            default                                   => $atLimit && $class === AeatFaultClassifier::RECORD,
        };
        if ($failNow) {
            return $this->failClaimed($issuerNif, $token, $claimed, $failure, $response, $now, $next);
        }

        if ($mode === self::MODE_LONE) {
            // One more refusal counted, so the next lone send goes to another suspect (rotation, LJD4-L1).
            $this->queue->parkClaim($issuerNif, $token, StoredBillingRecord::STATUS_HELD, $failure, true);

            return $this->pause($issuerNif, IssuerSubmissionPausedEvent::REASON_AWAITING_PROBE, $failure, $response, $now);
        }
        if ($mode === self::MODE_PROBE_NEWEST) {
            // MAX_SUSPECTS records and the newest one all refused: issuer-wide, or the newest record is bad too. The probe
            // is held (a suspect, confirmed alone before it can fail), so after the pause the probe goes to the record
            // before it: a bad record at the tail delays the queue by one pause, never blocks it (LJD3-L1).
            $this->queue->parkClaim($issuerNif, $token, StoredBillingRecord::STATUS_HELD, $failure, true);

            return $this->pause($issuerNif, IssuerSubmissionPausedEvent::REASON_ISSUER_REFUSED, $failure, $response, $now);
        }
        if ($mode === self::MODE_PROBE || $atLimit) {
            if ($this->queue->parkClaim($issuerNif, $token, StoredBillingRecord::STATUS_HELD, $failure, true) === 0) {
                // The claim was taken over meanwhile: the newer submission decides.
                return new BatchSubmissionResult($issuerNif, BatchSubmissionResult::STATE_FAILED, [], $response, $next, $this->queue->countPending($issuerNif, $now, $this->claimTimeout));
            }
            $this->logger?->warning('The AEAT refused Veri*Factu record {position} of {issuer} on its own ({failure}); it is held while other records go alone as probes.', [
                'issuer'   => $issuerNif,
                'position' => $claimed[0]->position,
                'failure'  => $failure,
            ]);
            if ($this->queue->countPending($issuerNif, $now, $this->claimTimeout) === 0) {
                return $this->pause($issuerNif, IssuerSubmissionPausedEvent::REASON_AWAITING_PROBE, $failure, $response, $now);
            }

            return new BatchSubmissionResult($issuerNif, BatchSubmissionResult::STATE_FAILED, [], $response, $next, $this->queue->countPending($issuerNif, $now, $this->claimTimeout));
        }

        $this->queue->releaseClaim($issuerNif, $token, $failure, true);
        $this->logger?->warning('The AEAT refused a submission of {count} Veri*Factu records as a whole; the next one is split to find the record at fault.', ['count' => count($claimed)]);

        return new BatchSubmissionResult($issuerNif, BatchSubmissionResult::STATE_FAILED, [], $response, $next, $this->queue->countPending($issuerNif, $now, $this->claimTimeout));
    }

    /**
     * Marks the claimed records failed (committed) and dispatches one event per record.
     *
     * @param list<StoredBillingRecord> $claimed
     */
    private function failClaimed(string $issuerNif, string $token, array $claimed, string $failure, AeatBatchResponse $response, DateTimeImmutable $now, DateTimeImmutable $next): BatchSubmissionResult
    {
        $hashes      = $this->queue->failClaim($issuerNif, $token, $failure, $now);
        $eventErrors = [];
        $failed      = [];
        foreach ($claimed as $stored) {
            $hash = strtoupper((string) $stored->record->hash);
            if (!in_array($hash, $hashes, true)) {
                continue;
            }
            $failed[] = $stored;
            $this->logger?->error('The AEAT refused Veri*Factu record {position} of {issuer} ({failure}); it is marked failed and is not sent again until it is requeued.', [
                'issuer'   => $issuerNif,
                'position' => $stored->position,
                'failure'  => $failure,
            ]);
            $this->dispatch(new RecordSubmissionFailedEvent($issuerNif, $stored->position, $hash, $failure, $stored, $response), VerifactuEvents::RECORD_SUBMISSION_FAILED, $eventErrors);
        }

        return new BatchSubmissionResult($issuerNif, BatchSubmissionResult::STATE_FAILED, [], $response, $next, $this->queue->countPending($issuerNif, $now, $this->claimTimeout), $eventErrors, $failed);
    }

    /**
     * Pauses the issuer (nothing is failed or reset; the suspects stay listed) and dispatches one alert after the
     * stored changes are committed.
     */
    private function pause(string $issuerNif, string $reason, string $failure, AeatBatchResponse $response, DateTimeImmutable $now): BatchSubmissionResult
    {
        $until    = $now->modify(sprintf('+%d seconds', max(1, $this->pauseSeconds)));
        $suspects = array_map(static fn (StoredBillingRecord $s): int => $s->position, $this->queue->suspects($issuerNif));
        $this->flowControl?->registerSubmission(self::PAUSE_KEY_PREFIX . $issuerNif, $now, max(1, $this->pauseSeconds));
        $this->logger?->error($reason === IssuerSubmissionPausedEvent::REASON_ISSUER_REFUSED
            ? 'The AEAT refuses every request of {issuer} ({failure}); no record is marked failed and the issuer is paused until {until}. Held records: {held}.'
            : 'Veri*Factu record(s) {held} of {issuer} are held ({failure}) and no other record is waiting to test the AEAT with; paused until {until}.', [
                'issuer'  => $issuerNif,
                'failure' => $failure,
                'until'   => $until->format(DATE_ATOM),
                'held'    => implode(', ', $suspects),
            ]);
        $eventErrors = [];
        $this->dispatch(new IssuerSubmissionPausedEvent($issuerNif, $failure, $response->faultErrorCode, $until, $response, $reason, $suspects), VerifactuEvents::ISSUER_SUBMISSION_PAUSED, $eventErrors);

        return new BatchSubmissionResult($issuerNif, BatchSubmissionResult::STATE_PAUSED, [], $response, $until, $this->queue->countPending($issuerNif, $now, $this->claimTimeout), $eventErrors, [], $reason);
    }

    /**
     * Whether an alert under $key is due (at most one per `pause_seconds`), registering it when it is.
     */
    private function alertDue(string $key, DateTimeImmutable $now): bool
    {
        $due = $this->flowControl?->nextSubmissionAt($key);
        if ($due instanceof DateTimeImmutable && $due > $now) {
            return false;
        }
        $this->flowControl?->registerSubmission($key, $now, max(1, $this->pauseSeconds));

        return true;
    }

    /**
     * Whether the issuer's previous request got no answer either (LJD3-I4), marking this one: a request without any
     * answer sets a marker in the flow control store for `pause_seconds`, an answered request clears it.
     */
    private function noAnswerBefore(string $issuerNif, DateTimeImmutable $now): bool
    {
        $key    = self::NO_ANSWER_KEY_PREFIX . $issuerNif;
        $marker = $this->flowControl?->nextSubmissionAt($key);
        $this->flowControl?->registerSubmission($key, $now, max(1, $this->pauseSeconds));

        return $marker instanceof DateTimeImmutable && $marker > $now;
    }

    /**
     * Clears the no-answer marker of the issuer (the AEAT answered), writing only when it is set.
     */
    private function clearNoAnswer(string $issuerNif, DateTimeImmutable $now): void
    {
        $key    = self::NO_ANSWER_KEY_PREFIX . $issuerNif;
        $marker = $this->flowControl?->nextSubmissionAt($key);
        if ($marker instanceof DateTimeImmutable && $marker > $now) {
            $this->flowControl?->registerSubmission($key, $now, 0);
        }
    }

    /**
     * @param list<array{event: string, exception: Throwable}> $eventErrors
     */
    private function dispatch(object $event, string $name, array &$eventErrors): void
    {
        try {
            $this->eventDispatcher->dispatch($event, $name);
        } catch (Throwable $exception) {
            $this->logger?->error('A {event} listener failed ({exception_class}); the stored AEAT answer is kept.', [
                'event'           => $name,
                'exception_class' => $exception::class,
            ]);
            $eventErrors[] = ['event' => $name, 'exception' => $exception];
        }
    }

    private function outcome(StoredBillingRecord $stored, AeatBatchResponse $response): RecordSubmissionOutcome
    {
        $line = $response->lineFor($stored->record);
        if (!$line instanceof AeatRecordResponse) {
            // EstadoEnvio Correcto: every record of the request was accepted. Otherwise the AEAT said nothing about this
            // record: it goes back to pending and is sent again.
            return $response->submissionStatus === AeatBatchResponse::ENVIO_CORRECT
                ? new RecordSubmissionOutcome($stored, RecordSubmissionStatus::Accepted, $response->csv)
                : new RecordSubmissionOutcome($stored, null, null, null, 'The AEAT answer has no line for this record.');
        }

        $status      = $line->status;
        $description = $line->errorDescription;
        if ($line->isDuplicate()) {
            $status      = $this->duplicateStatus($stored, $line);
            $description = sprintf(
                '%s (RegistroDuplicado: IdPeticionRegistroDuplicado %s, EstadoRegistroDuplicado %s)',
                $description ?? 'Duplicate',
                $line->duplicateRequestId ?? '-',
                (string) $line->duplicateStatus,
            );
        }

        return new RecordSubmissionOutcome(
            $stored,
            $status,
            $status->isRegistered() ? $response->csv : null,
            $line->errorCode,
            $description,
            $line,
        );
    }

    /**
     * The state of a record the AEAT answered as a duplicate (see the class description).
     */
    private function duplicateStatus(StoredBillingRecord $stored, AeatRecordResponse $line): RecordSubmissionStatus
    {
        $same = null;
        if ($this->duplicateConfirmation instanceof DuplicateConfirmationInterface) {
            try {
                $same = $this->duplicateConfirmation->isSameRecord($stored->record, $line);
            } catch (Throwable $exception) {
                $this->logger?->warning('The duplicate confirmation failed ({exception_class}); the record is stored as unconfirmed.', ['exception_class' => $exception::class]);
            }
        }
        if ($same === false) {
            return RecordSubmissionStatus::Rejected;
        }
        if ($same === true) {
            return RecordSubmissionStatus::fromDuplicate((string) $line->duplicateStatus, $stored->record->recordType) ?? RecordSubmissionStatus::Unconfirmed;
        }

        // Unconfirmed. Transmitted before this request (attempts counts this one): an earlier send may have registered
        // it, or another record may hold the id; never report it as accepted. Never transmitted before: not ours.
        return $stored->submissionAttempts > 1 ? RecordSubmissionStatus::Unconfirmed : RecordSubmissionStatus::Rejected;
    }

    private function batchSize(): int
    {
        return max(1, min(SoapEnvelopeBuilder::MAX_RECORDS_PER_SUBMISSION, $this->batchSize));
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? new DateTimeImmutable();
    }
}
