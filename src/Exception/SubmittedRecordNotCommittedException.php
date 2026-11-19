<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Exception;

use Nowo\VerifactuBundle\Model\BillingRecord;
use RuntimeException;
use Throwable;

/**
 * process(..., submitToAeat: true) ran inside your transaction, the AEAT already received the record, and then a
 * database failure (the record store could not save the AEAT answer, an AFTER_AEAT_SUBMISSION listener failed with a
 * database error, or the chain head is no longer in your transaction) means the chain head will not commit with your
 * transaction. Committing would silently lose the head while the AEAT holds the record: the next record would chain
 * onto the previous head and fork the chain at the AEAT.
 *
 * **Never retry it automatically.** It is not a lock conflict: calling process() again for the same invoice would send
 * a second record to the AEAT. On purpose it has no previous exception, so conflict classifiers (transaction-doctrine's
 * TransientFailureClassifier, a 409 / CONFLICT_001 responder, Messenger retry strategies keyed on the cause) never take
 * it for a retryable failure: answer it as a 500, and in a Messenger handler wrap it in
 * UnrecoverableMessageHandlingException. The database failure is in $databaseError, the head loss in $headLoss.
 *
 * Roll back your transaction (if the connection is still open; a rollback error is expected when the database already
 * ended it), then call BillingRecordProcessor::persistSubmittedRecord($e->record, $e->submission) before chaining
 * anything else for the issuer. See docs/CONCURRENCY.md, "Submitted record not committed".
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class SubmittedRecordNotCommittedException extends RuntimeException
{
    /**
     * @param BillingRecord $record the chained record the AEAT received (hash, XML and signed XML included)
     * @param array<string, mixed> $submission the AEAT submission result (success, reference / CSV, errors)
     * @param Throwable|null $databaseError the database failure of the record store or of an AFTER_AEAT_SUBMISSION
     *                                      listener, if any (deliberately not the previous exception)
     * @param ChainTransactionLostException|null $headLoss set when the head was no longer in the transaction (the
     *                                                     connection was closed)
     */
    public function __construct(
        public readonly BillingRecord $record,
        public readonly array $submission,
        public readonly ?Throwable $databaseError = null,
        public readonly ?ChainTransactionLostException $headLoss = null,
    ) {
        parent::__construct('The AEAT received the Veri*Factu record, but a database failure means its chain head will not commit with your transaction. Do not retry: roll back, then persist this record and its AEAT answer with persistSubmittedRecord() before chaining anything else for the issuer (docs/CONCURRENCY.md).');
    }
}
