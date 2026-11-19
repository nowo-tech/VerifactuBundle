<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Repository;

use Nowo\VerifactuBundle\Exception\ChainTransactionLostException;

/**
 * A repository that can tell whether its connection is inside a transaction.
 *
 * BillingRecordProcessor asks the hash chain repository: while a transaction is open on the chain's connection (the
 * host's own transaction around process()), the new chain head is not committed yet, so a database failure raised by
 * a listener before the AEAT submission is rethrown instead of contained, and the head is read again before and after
 * the AEAT submission (REQ-RUNTIME-003 rule 8, docs/CONCURRENCY.md).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
interface TransactionStateAwareInterface
{
    /**
     * Whether the repository's connection is inside a transaction (any nesting level).
     */
    public function isInsideTransaction(): bool;

    /**
     * Inside a transaction, reads the issuer's chain head again and checks it is $hash; throws
     * ChainTransactionLostException when the read fails or returns another head (closing the connection first only
     * where the database already ended the transaction: MySQL / MariaDB).
     *
     * @throws ChainTransactionLostException
     */
    public function verifyHeadInTransaction(string $issuerNif, string $hash): void;
}
