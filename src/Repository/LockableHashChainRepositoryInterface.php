<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Repository;

use Nowo\VerifactuBundle\Model\HashChainState;

/**
 * Hash chain repository able to serialize the read-then-write of a chain head per issuer.
 *
 * BillingRecordProcessor runs "read last state → compute hash → store new state" inside
 * withChainLock() when the configured repository implements this interface, so two concurrent
 * requests for the same issuer cannot chain onto the same previous record (forked chain).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
interface LockableHashChainRepositoryInterface extends HashChainRepositoryInterface
{
    /**
     * Runs $operation while holding an exclusive lock on the issuer's chain head.
     *
     * Implementations should read the head once, under the lock (a locking read such as SELECT … FOR UPDATE, which
     * sees the latest committed head whatever the transaction's snapshot), and pass it to $operation (null for an
     * empty chain). A plain read inside the lock can return a stale head (MySQL / MariaDB REPEATABLE READ) and fork
     * the chain. When an implementation calls $operation() without an argument, BillingRecordProcessor falls back to
     * getLastState() (behaviour of 1.1 – 1.4): that plain read CAN FORK THE CHAIN on MySQL / MariaDB inside a host
     * transaction. Always pass the locked head.
     *
     * @template T
     *
     * @param callable(?HashChainState=): T $operation
     *
     * @return T
     */
    public function withChainLock(string $issuerNif, callable $operation): mixed;
}
