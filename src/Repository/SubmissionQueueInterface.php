<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Repository;

use DateTimeImmutable;
use Nowo\VerifactuBundle\Model\RecordSubmissionOutcome;
use Nowo\VerifactuBundle\Model\RecordSubmissionStatus;
use Nowo\VerifactuBundle\Model\StoredBillingRecord;

/**
 * The record log seen as the queue of records to send to the AEAT (1.7, batch submission). Implemented by the Doctrine
 * and in-memory record stores; a host store may implement it to use Service\BillingRecordBatchSubmitter.
 *
 * Every record stays in the log forever; only its submission state changes: pending → sending (claimed by one
 * submission, with a claim token and time) → accepted / accepted_errors / rejected / unconfirmed, back to pending when
 * the submission got no answer for it, or held and then failed when the AEAT kept refusing it on its own while the
 * next record got an answer (see BillingRecordBatchSubmitter).
 * A claim older than the claim timeout is stale (the process died after sending) and the records are claimed again.
 *
 * Records without an AEAT environment (rows written before 1.6) are never waiting: they could be test records, and
 * the bundle never decides on its own to send them to production (UPGRADING, 1.7).
 *
 * claimNext(), countPending() and hasSubmissionInFlight() are called inside the issuer's chain lock, so two
 * submissions of the same issuer never claim at the same time. The other writes commit on their own: they must not
 * run inside a host transaction (isInsideTransaction()).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
interface SubmissionQueueInterface
{
    /**
     * The issuer's stored records in chain order (the record log).
     *
     * @return iterable<StoredBillingRecord>
     */
    public function findByIssuer(string $issuerNif): iterable;

    /**
     * Whether a submission of the issuer holds a claim newer than the timeout (one submission in flight per issuer).
     */
    public function hasSubmissionInFlight(string $issuerNif, DateTimeImmutable $now, int $claimTimeoutSeconds): bool;

    /**
     * Records waiting to be sent: pending, plus those of a stale claim (records without an environment excluded).
     */
    public function countPending(string $issuerNif, DateTimeImmutable $now, int $claimTimeoutSeconds): int;

    /**
     * Claims up to $limit records waiting to be sent, in chain order, and returns them (attempts unchanged: an attempt
     * is counted by markTransmitted()). The claim stops before a record of another AEAT environment than the first one
     * and before a record whose AEAT identity (invoice id + operation) is already in the claim (a correction goes in a
     * new submission, Servicios web v1.0.3 §9.1.2). When the first waiting record has submission failures, the limit
     * is halved once per failure, down to 1 (Submission\BatchSplit::claimLimit()): a batch the AEAT refuses as a
     * whole is split until the record at fault goes on its own.
     *
     * @return list<StoredBillingRecord>
     */
    public function claimNext(string $issuerNif, int $limit, string $claimToken, DateTimeImmutable $now, int $claimTimeoutSeconds): array;

    /**
     * Counts one attempt for every record of the claim: called right before the request is transmitted. Returns the
     * number of records updated (0 when the claim was taken over).
     */
    public function markTransmitted(string $issuerNif, string $claimToken): int;

    /**
     * Stores the outcomes of a claim in one transaction (a null status puts the record back to pending; submission
     * failures are reset) and returns the hashes actually updated (a record whose claim was taken over by a newer
     * submission is left alone).
     *
     * @param list<RecordSubmissionOutcome> $outcomes
     *
     * @return list<string>
     */
    public function recordOutcomes(string $issuerNif, string $claimToken, array $outcomes, DateTimeImmutable $now): array;

    /**
     * Puts every record of the claim back to pending (the submission got no per-record answer). A non-null $failure
     * replaces the stored error text; $countFailure adds one submission failure to each record (the AEAT refused the
     * request as a whole, see AeatBatchResponse::isRecordRefusal()).
     */
    public function releaseClaim(string $issuerNif, string $claimToken, ?string $failure = null, bool $countFailure = false): int;

    /**
     * The issuer's suspects (held and confirming records, see the circuit breaker in BillingRecordBatchSubmitter), in
     * chain order. They are not waiting.
     *
     * @return list<StoredBillingRecord>
     */
    public function suspects(string $issuerNif): array;

    /**
     * Position of the newest waiting record (for a probe), or null.
     */
    public function newestWaitingPosition(string $issuerNif, DateTimeImmutable $now, int $claimTimeoutSeconds): ?int;

    /**
     * Claims one record by position (pending, held, confirming, or of a stale claim) and returns it, or null.
     */
    public function claimRecord(string $issuerNif, int $position, string $claimToken, DateTimeImmutable $now, int $claimTimeoutSeconds): ?StoredBillingRecord;

    /**
     * Ends a claim without an answer by putting its records in $status (pending, held or confirming); a non-null
     * $failure replaces the stored error; $countFailure adds one submission failure. Returns the records updated.
     */
    public function parkClaim(string $issuerNif, string $claimToken, string $status, ?string $failure = null, bool $countFailure = false): int;

    /**
     * Marks every record of the claim failed (one more submission failure) in one transaction and returns their hashes.
     *
     * @return list<string>
     */
    public function failClaim(string $issuerNif, string $claimToken, string $failure, DateTimeImmutable $now): array;

    /**
     * Turns the issuer's held records into confirming ones (a probe was answered). Returns the records updated.
     */
    public function confirmSuspects(string $issuerNif): int;

    /**
     * Marks one pending, held or confirming record failed by hand (one more submission failure, so --retry-failed can
     * requeue it) and returns it as it was, or null when it is in no such state.
     */
    public function failRecord(string $issuerNif, int $position, string $reason, DateTimeImmutable $now): ?StoredBillingRecord;

    /**
     * Puts back to pending, with no submission failures, the issuer's records the batch submitter marked failed
     * (submission failures ≥ 1) once the cause was fixed. Records stored as failed by process(..., submitToAeat: true)
     * (an AEAT `Incorrecto`) are left alone.
     */
    public function requeueFailed(string $issuerNif): int;

    /**
     * Resolves an `unconfirmed` record after the host checked it at the AEAT: accepted / accepted with errors (with the
     * CSV) or rejected. False when the record is not unconfirmed.
     */
    public function resolveUnconfirmed(string $issuerNif, int $position, RecordSubmissionStatus $status, ?string $reference, DateTimeImmutable $now): bool;

    /**
     * Issuers with records waiting to be sent, in flight, held or confirming.
     *
     * @return list<string>
     */
    public function issuersWithPendingRecords(): array;

    /**
     * Whether the store's connection is inside a transaction (the submitter refuses to run there: its after-commit
     * events need its own commits).
     */
    public function isInsideTransaction(): bool;
}
