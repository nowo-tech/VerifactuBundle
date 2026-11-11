<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Service;

use DateTimeImmutable;
use LogicException;
use Nowo\VerifactuBundle\Client\BatchAeatSubmissionClientInterface;
use Nowo\VerifactuBundle\Client\SoapEnvelopeBuilder;
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
 * Concurrency and retries: the next batch is claimed inside the issuer's chain lock (one batch in flight per issuer, in
 * chain order); the HTTP request runs outside any transaction; the answers are stored in one transaction and only then
 * are events dispatched. A request without an answer (transport error, HTTP status, SoapFault) puts the batch back to
 * pending. A claim older than the claim timeout (the process died) is claimed again. Resending is idempotent: the same
 * stored XML (same hash) is sent, and an AEAT "duplicate" answer for a record sent before (attempt 2 or later) means an
 * earlier send registered it, so the record takes the state the AEAT holds (Correcta / AceptadaConErrores). On a first
 * attempt a duplicate is a real conflict (the invoice id was already used) and the record is rejected.
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

    /** Flow control key shared by every issuer (submission.flow_control_scope: global). */
    public const GLOBAL_FLOW_CONTROL_KEY = '*';

    /**
     * @param int $batchSize records per request, 1 – 1000 (AEAT Servicios web v1.0.3 p. 10)
     * @param int $claimTimeout seconds after which a claim is stale and its records are claimed again
     * @param int $defaultWait seconds to wait when the AEAT gave no TiempoEsperaEnvio (initially 60, art. 16.2)
     * @param string $flowControlScope issuer (one wait per issuer NIF) or global (one wait for every issuer)
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
        $flowKey   = $this->flowControlScope === 'global' ? self::GLOBAL_FLOW_CONTROL_KEY : $issuerNif;
        $token     = bin2hex(random_bytes(16));

        // Claimed under the issuer's chain lock: one batch in flight per issuer, in chain order, and the flow control
        // check serialised with the previous submission (it registers the wait before it releases its records).
        $claim = function () use ($issuerNif, $batchSize, $now, $flowKey, $token): BatchSubmissionResult|array {
            if ($this->queue->hasSubmissionInFlight($issuerNif, $now, $this->claimTimeout)) {
                return new BatchSubmissionResult($issuerNif, BatchSubmissionResult::STATE_IN_FLIGHT);
            }
            $waiting = $this->queue->countPending($issuerNif, $now, $this->claimTimeout);
            if ($waiting === 0) {
                return new BatchSubmissionResult($issuerNif, BatchSubmissionResult::STATE_IDLE);
            }
            $next = $this->flowControl?->nextSubmissionAt($flowKey);
            if ($next instanceof DateTimeImmutable && $next > $now && $waiting < $batchSize) {
                return new BatchSubmissionResult($issuerNif, BatchSubmissionResult::STATE_THROTTLED, nextSubmissionAt: $next, remaining: $waiting);
            }

            return $this->queue->claimNext($issuerNif, $batchSize, $token, $now, $this->claimTimeout);
        };
        $claimed = $this->hashChainRepository instanceof LockableHashChainRepositoryInterface
            ? $this->hashChainRepository->withChainLock($issuerNif, static fn (): BatchSubmissionResult|array => $claim())
            : $claim();
        if ($claimed instanceof BatchSubmissionResult) {
            return $claimed;
        }
        if ($claimed === []) {
            return new BatchSubmissionResult($issuerNif, BatchSubmissionResult::STATE_IDLE);
        }

        try {
            $response = $this->client->submitBatch(array_map(static fn (StoredBillingRecord $stored): BillingRecord => $stored->record, $claimed));
        } catch (Throwable $exception) {
            // No request left the process, or its answer is unknown: the records go back to pending, untouched.
            $this->queue->releaseClaim($issuerNif, $token, $exception::class);

            throw $exception;
        }

        // Registered before the records are released, so the next claim (under the lock) sees the new wait.
        $wait = $response->waitSeconds ?? $this->defaultWait;
        $next = $now->modify(sprintf('+%d seconds', max(0, $wait)));
        $this->flowControl?->registerSubmission($flowKey, $now, $wait);

        if ($response->isFailure()) {
            $this->queue->releaseClaim($issuerNif, $token, (string) $response->failure);
            $this->logger?->warning('The AEAT submission of {count} Veri*Factu records got no answer; they stay pending.', ['count' => count($claimed)]);

            return new BatchSubmissionResult($issuerNif, BatchSubmissionResult::STATE_FAILED, [], $response, $next, $this->queue->countPending($issuerNif, $now, $this->claimTimeout));
        }

        $outcomes = array_map(fn (StoredBillingRecord $stored): RecordSubmissionOutcome => $this->outcome($stored, $response), $claimed);
        $updated  = $this->queue->recordOutcomes($issuerNif, $token, $outcomes, $now);

        // After the commit of the answers: one event per record whose answer was stored by this submission.
        $eventErrors = [];
        foreach ($outcomes as $outcome) {
            if ($outcome->status === null || !in_array(strtoupper((string) $outcome->stored->record->hash), $updated, true)) {
                continue;
            }
            try {
                $this->eventDispatcher->dispatch(new RecordSubmittedEvent($outcome, $response), VerifactuEvents::AFTER_RECORD_SUBMISSION);
            } catch (Throwable $exception) {
                $this->logger?->error('A {event} listener failed ({exception_class}); the stored AEAT answer is kept.', [
                    'event'           => VerifactuEvents::AFTER_RECORD_SUBMISSION,
                    'exception_class' => $exception::class,
                ]);
                $eventErrors[] = ['event' => VerifactuEvents::AFTER_RECORD_SUBMISSION, 'exception' => $exception];
            }
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

    private function outcome(StoredBillingRecord $stored, AeatBatchResponse $response): RecordSubmissionOutcome
    {
        $line = $response->lineFor($stored->record);
        if (!$line instanceof AeatRecordResponse) {
            // EstadoEnvio Correcto: every record of the request was accepted. Otherwise the AEAT said nothing about this
            // record: it goes back to pending and is sent again (idempotent, see the class description).
            return $response->submissionStatus === AeatBatchResponse::ENVIO_CORRECT
                ? new RecordSubmissionOutcome($stored, RecordSubmissionStatus::Accepted, $response->csv)
                : new RecordSubmissionOutcome($stored, null, null, null, 'The AEAT answer has no line for this record.');
        }

        $status = $line->status;
        if ($line->isDuplicate()) {
            // Attempt 2 or later: an earlier send (whose answer was lost) may have registered this very record, and the
            // AEAT tells which state it holds. First attempt: another record already used this invoice id.
            $status = $stored->submissionAttempts > 1
                ? RecordSubmissionStatus::fromDuplicate((string) $line->duplicateStatus)
                : RecordSubmissionStatus::Rejected;
        }

        return new RecordSubmissionOutcome(
            $stored,
            $status,
            $status->isRegistered() ? $response->csv : null,
            $line->errorCode,
            $line->errorDescription,
            $line,
        );
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
