<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Repository;

/**
 * A repository that can tell whether its connection is inside a transaction.
 *
 * BillingRecordProcessor asks the hash chain repository: while a transaction is open on the chain's connection (the
 * host's own transaction around process()), the new chain head is not committed yet, so a database failure raised by
 * a listener before the AEAT submission is rethrown instead of contained (REQ-RUNTIME-003 rule 8, docs/CONCURRENCY.md).
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
}
