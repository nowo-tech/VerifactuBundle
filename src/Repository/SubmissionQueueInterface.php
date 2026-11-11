<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Repository;

use DateTimeImmutable;
use Nowo\VerifactuBundle\Model\RecordSubmissionOutcome;
use Nowo\VerifactuBundle\Model\StoredBillingRecord;

/**
 * The record log seen as the queue of records to send to the AEAT (1.7, batch submission). Implemented by the Doctrine
 * and in-memory record stores; a host store may implement it to use Service\BillingRecordBatchSubmitter.
 *
 * Every record stays in the log forever; only its submission state changes: pending → sending (claimed by one
 * submission, with a claim token and time) → accepted / accepted_errors / rejected, or back to pending when the
 * submission got no answer for it. A claim older than the claim timeout is stale (the process died after sending) and
 * the records are claimed again; the resend is idempotent (see BillingRecordBatchSubmitter).
 *
 * claimNext(), countPending() and hasSubmissionInFlight() are called inside the issuer's chain lock, so two
 * submissions of the same issuer never claim at the same time. recordOutcomes() and releaseClaim() commit on their
 * own: they must not run inside a host transaction (isInsideTransaction()).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
interface SubmissionQueueInterface
{
    /**
     * Whether a submission of the issuer holds a claim newer than the timeout (one submission in flight per issuer).
     */
    public function hasSubmissionInFlight(string $issuerNif, DateTimeImmutable $now, int $claimTimeoutSeconds): bool;

    /**
     * Records waiting to be sent: pending, plus those of a stale claim.
     */
    public function countPending(string $issuerNif, DateTimeImmutable $now, int $claimTimeoutSeconds): int;

    /**
     * Claims up to $limit records waiting to be sent, in chain order, and returns them with their new attempt count.
     * The claim stops before a record of another AEAT environment than the first one and before a record whose AEAT
     * identity (invoice id + operation) is already in the claim (a correction goes in a new submission, Servicios web
     * v1.0.3 §9.1.2).
     *
     * @return list<StoredBillingRecord>
     */
    public function claimNext(string $issuerNif, int $limit, string $claimToken, DateTimeImmutable $now, int $claimTimeoutSeconds): array;

    /**
     * Stores the outcomes of a claim in one transaction (a null status puts the record back to pending) and returns
     * the hashes actually updated (a record whose claim was taken over by a newer submission is left alone).
     *
     * @param list<RecordSubmissionOutcome> $outcomes
     *
     * @return list<string>
     */
    public function recordOutcomes(string $issuerNif, string $claimToken, array $outcomes, DateTimeImmutable $now): array;

    /**
     * Puts every record of the claim back to pending (the submission got no answer), keeping the failure text.
     */
    public function releaseClaim(string $issuerNif, string $claimToken, string $failure): int;

    /**
     * Issuers with records waiting to be sent or in flight.
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
