<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Service;

use DateTimeImmutable;
use LogicException;
use Nowo\VerifactuBundle\Client\BatchAeatSubmissionClientInterface;
use Nowo\VerifactuBundle\Client\SoapEnvelopeBuilder;
use Nowo\VerifactuBundle\Event\IssuerSubmissionPausedEvent;
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
use Nowo\VerifactuBundle\Submission\BatchSplit;
use Nowo\VerifactuBundle\Submission\DuplicateConfirmationInterface;
use Nowo\VerifactuBundle\Submission\FlowControlInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function array_map;
use function bin2hex;
use function count;
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

    /**
     * @param int $batchSize records per request, 1 – 1000 (AEAT Servicios web v1.0.3 p. 10)
     * @param int $claimTimeout seconds after which a claim is stale and its records are claimed again
     * @param int $defaultWait seconds to wait when the AEAT gave no TiempoEsperaEnvio (initially 60, art. 16.2)
     * @param string $flowControlScope issuer (one wait per issuer NIF) or global (one wait for every issuer)
     * @param int $maxAttempts refusals of a record sent on its own before it is held (then failed or the issuer paused)
     * @param int $pauseSeconds how long an issuer stays paused after an issuer-wide refusal (circuit breaker)
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
            $waiting = $this->queue->countPending($issuerNif, $now, $this->claimTimeout);
            if ($waiting === 0) {
                return new BatchSubmissionResult($issuerNif, BatchSubmissionResult::STATE_IDLE);
            }
            $pausedUntil = $this->flowControl?->nextSubmissionAt(self::PAUSE_KEY_PREFIX . $issuerNif);
            if ($pausedUntil instanceof DateTimeImmutable && $pausedUntil > $now) {
                return new BatchSubmissionResult($issuerNif, BatchSubmissionResult::STATE_PAUSED, nextSubmissionAt: $pausedUntil, remaining: $waiting);
            }
            // A held record (refused alone max_attempts times): the next record goes alone, as a probe.
            $held = $this->queue->heldRecord($issuerNif);
            if ($held instanceof StoredBillingRecord) {
                $batchSize = 1;
            }
            $next     = $this->flowControl?->nextSubmissionAt($flowKey);
            $mustWait = $next instanceof DateTimeImmutable && $next > $now;
            // Art. 16.2 c: only a submission carrying the record-design limit (1000 records) may skip the wait.
            if ($mustWait && ($batchSize < SoapEnvelopeBuilder::MAX_RECORDS_PER_SUBMISSION || $waiting < SoapEnvelopeBuilder::MAX_RECORDS_PER_SUBMISSION)) {
                return new BatchSubmissionResult($issuerNif, BatchSubmissionResult::STATE_THROTTLED, nextSubmissionAt: $next, remaining: $waiting);
            }

            $claimed = $this->queue->claimNext($issuerNif, $batchSize, $token, $now, $this->claimTimeout);
            if ($mustWait && $claimed !== [] && count($claimed) < SoapEnvelopeBuilder::MAX_RECORDS_PER_SUBMISSION) {
                // The claim stopped short of 1000 records (an environment change, a second record of one invoice, a
                // split batch): it is not a full submission, so it waits like any other.
                $this->queue->releaseClaim($issuerNif, $token);

                return new BatchSubmissionResult($issuerNif, BatchSubmissionResult::STATE_THROTTLED, nextSubmissionAt: $next, remaining: $waiting);
            }
            if ($global && $claimed !== []) {
                // Reserve the shared slot before the global lock is released: another issuer checking now must wait.
                $this->flowControl?->registerSubmission($flowKey, $now, $this->defaultWait);
            }

            return ['claimed' => $claimed, 'held' => $held];
        };
        $outcome = $this->underLocks($issuerNif, $global, $claim);
        if ($outcome instanceof BatchSubmissionResult) {
            return $outcome;
        }
        ['claimed' => $claimed, 'held' => $held] = $outcome;
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
            // No request left the process, or its answer is unknown: the records go back to pending, untouched.
            $this->queue->releaseClaim($issuerNif, $token, $exception::class);

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
            return $this->failed($issuerNif, $token, $claimed, $held, $response, $batchSize, $now, $next);
        }

        $outcomes = array_map(fn (StoredBillingRecord $stored): RecordSubmissionOutcome => $this->outcome($stored, $response), $claimed);
        $updated  = $this->queue->recordOutcomes($issuerNif, $token, $outcomes, $now);

        // After the commit of the answers: one event per record whose answer was stored by this submission.
        $eventErrors = [];
        foreach ($outcomes as $outcome) {
            if ($outcome->status === null || !in_array(strtoupper((string) $outcome->stored->record->hash), $updated, true)) {
                continue;
            }
            $this->dispatch(new RecordSubmittedEvent($outcome, $response), VerifactuEvents::AFTER_RECORD_SUBMISSION, $eventErrors);
        }

        // The probe got an answer: the AEAT accepts this issuer's requests, so the held record was at fault.
        $failed = $held instanceof StoredBillingRecord ? $this->failHeld($issuerNif, $held, $response, $now, $eventErrors) : [];

        return new BatchSubmissionResult(
            $issuerNif,
            BatchSubmissionResult::STATE_SENT,
            $outcomes,
            $response,
            $next,
            $this->queue->countPending($issuerNif, $now, $this->claimTimeout),
            $eventErrors,
            $failed,
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
        return $this->queue->requeueFailed(strtoupper(trim($issuerNif)));
    }

    /**
     * The issuer's records that need a person: unconfirmed duplicates, a held record and failed records, in chain order.
     *
     * @return list<StoredBillingRecord>
     */
    public function recordsToReview(string $issuerNif): array
    {
        $review = [];
        foreach ($this->queue->findByIssuer(strtoupper(trim($issuerNif))) as $stored) {
            if (in_array($stored->submissionStatus, [StoredBillingRecord::STATUS_UNCONFIRMED, StoredBillingRecord::STATUS_HELD, StoredBillingRecord::STATUS_FAILED], true)) {
                $review[] = $stored;
            }
        }

        return $review;
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
     * @param callable(): (array{claimed: list<StoredBillingRecord>, held: ?StoredBillingRecord}|BatchSubmissionResult) $claim
     *
     * @return array{claimed: list<StoredBillingRecord>, held: ?StoredBillingRecord}|BatchSubmissionResult
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
     * A request without a per-record answer.
     *
     * Circuit breaker: a refusal counts against the records only for a SOAP `Client` fault (AeatBatchResponse::
     * isRecordRefusal()). The batch is halved until the record at fault goes alone; after enough refusals on its own it is
     * held, and the next record goes alone. If that probe gets an answer, the held record is marked failed; if the probe
     * is refused as well, the error is issuer-wide (a header or certificate problem no split can fix): nothing is marked
     * failed, every record of the issuer is back to pending without failures, the issuer is paused for `pause_seconds`
     * and ISSUER_SUBMISSION_PAUSED is dispatched once.
     *
     * @param list<StoredBillingRecord> $claimed
     */
    private function failed(string $issuerNif, string $token, array $claimed, ?StoredBillingRecord $held, AeatBatchResponse $response, int $batchSize, DateTimeImmutable $now, DateTimeImmutable $next): BatchSubmissionResult
    {
        $failure = (string) $response->failure;
        if (!$response->isRecordRefusal()) {
            // Transport failure, Server fault, HTTP / non-SOAP error: nothing points at the records; they stay pending.
            $this->queue->releaseClaim($issuerNif, $token, $failure);
            $this->logger?->warning('The AEAT submission of {count} Veri*Factu records got no answer; they stay pending.', ['count' => count($claimed)]);

            return new BatchSubmissionResult($issuerNif, BatchSubmissionResult::STATE_FAILED, [], $response, $next, $this->queue->countPending($issuerNif, $now, $this->claimTimeout));
        }

        if ($held instanceof StoredBillingRecord) {
            // The probe was refused too: issuer-wide.
            $this->queue->releaseClaim($issuerNif, $token, $failure);

            return $this->pause($issuerNif, $failure, $response, $now);
        }

        if (count($claimed) === 1 && $claimed[0]->submissionFailures + 1 >= $this->failureLimit($batchSize)) {
            if ($this->queue->holdClaim($issuerNif, $token, $failure) === 0) {
                // The claim was taken over meanwhile: the newer submission decides.
                return new BatchSubmissionResult($issuerNif, BatchSubmissionResult::STATE_FAILED, [], $response, $next, $this->queue->countPending($issuerNif, $now, $this->claimTimeout));
            }
            if ($this->queue->countPending($issuerNif, $now, $this->claimTimeout) === 0) {
                // Nothing to probe with: never fail a record on its own word.
                return $this->pause($issuerNif, $failure, $response, $now);
            }
            $this->logger?->warning('The AEAT refused Veri*Factu record {position} of {issuer} on its own {attempts} times; it is held while the next record goes alone.', [
                'issuer'   => $issuerNif,
                'position' => $claimed[0]->position,
                'attempts' => $claimed[0]->submissionFailures + 1,
            ]);

            return new BatchSubmissionResult($issuerNif, BatchSubmissionResult::STATE_FAILED, [], $response, $next, $this->queue->countPending($issuerNif, $now, $this->claimTimeout));
        }

        $this->queue->releaseClaim($issuerNif, $token, $failure, true);
        $this->logger?->warning('The AEAT refused a submission of {count} Veri*Factu records as a whole; the next one is split to find the record at fault.', ['count' => count($claimed)]);

        return new BatchSubmissionResult($issuerNif, BatchSubmissionResult::STATE_FAILED, [], $response, $next, $this->queue->countPending($issuerNif, $now, $this->claimTimeout));
    }

    /**
     * Marks the held record failed (committed) and dispatches its event.
     *
     * @param list<array{event: string, exception: Throwable}> $eventErrors
     *
     * @return list<StoredBillingRecord>
     */
    private function failHeld(string $issuerNif, StoredBillingRecord $held, AeatBatchResponse $response, DateTimeImmutable $now, array &$eventErrors): array
    {
        $hashes = $this->queue->resolveHeld($issuerNif, true, $now);
        $hash   = strtoupper((string) $held->record->hash);
        if (!in_array($hash, $hashes, true)) {
            return [];
        }
        $failure = (string) $held->submissionError;
        $this->logger?->error('The AEAT refused Veri*Factu record {position} of {issuer} while the next record was answered; it is marked failed and is not sent again until it is requeued.', [
            'issuer'   => $issuerNif,
            'position' => $held->position,
        ]);
        $this->dispatch(new RecordSubmissionFailedEvent($issuerNif, $held->position, $hash, $failure, $held, $response), VerifactuEvents::RECORD_SUBMISSION_FAILED, $eventErrors);

        return [$held];
    }

    /**
     * Issuer-wide refusal: no record is failed, the issuer is paused and one alert is dispatched (after the commit).
     */
    private function pause(string $issuerNif, string $failure, AeatBatchResponse $response, DateTimeImmutable $now): BatchSubmissionResult
    {
        $this->queue->resolveHeld($issuerNif, false, $now);
        $until = $now->modify(sprintf('+%d seconds', max(1, $this->pauseSeconds)));
        $this->flowControl?->registerSubmission(self::PAUSE_KEY_PREFIX . $issuerNif, $now, max(1, $this->pauseSeconds));
        $this->logger?->error('The AEAT refuses every request of {issuer} ({failure}); no record is marked failed and the issuer is paused until {until}.', [
            'issuer'  => $issuerNif,
            'failure' => $failure,
            'until'   => $until->format(DATE_ATOM),
        ]);
        $eventErrors = [];
        $this->dispatch(new IssuerSubmissionPausedEvent($issuerNif, $failure, $response->faultErrorCode, $until, $response), VerifactuEvents::ISSUER_SUBMISSION_PAUSED, $eventErrors);

        return new BatchSubmissionResult($issuerNif, BatchSubmissionResult::STATE_PAUSED, [], $response, $until, $this->queue->countPending($issuerNif, $now, $this->claimTimeout), $eventErrors);
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
