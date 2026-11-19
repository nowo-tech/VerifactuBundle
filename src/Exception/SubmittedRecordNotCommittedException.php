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
 * Roll back your transaction (if the connection is still open; a rollback error is expected when the database already
 * ended it), then persist exactly this record ($record, with its hash) and its AEAT answer ($submission, with the CSV)
 * in a new transaction before chaining anything else for the issuer. Never call process() for the same invoice again.
 * See docs/CONCURRENCY.md, "Submitted record not committed".
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class SubmittedRecordNotCommittedException extends RuntimeException
{
    /**
     * @param BillingRecord $record the chained record the AEAT received (hash, XML and signed XML included)
     * @param array<string, mixed> $submission the AEAT submission result (success, reference / CSV, errors)
     * @param Throwable|null $previous the database failure, or the ChainTransactionLostException of the head check
     */
    public function __construct(
        public readonly BillingRecord $record,
        public readonly array $submission,
        ?Throwable $previous = null,
    ) {
        parent::__construct('The AEAT received the Veri*Factu record, but a database failure means its chain head will not commit with your transaction. Roll back, then persist this record and its AEAT answer before chaining anything else for the issuer (docs/CONCURRENCY.md).', 0, $previous);
    }
}
