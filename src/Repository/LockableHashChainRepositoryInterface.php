<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Repository;

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
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function withChainLock(string $issuerNif, callable $operation): mixed;
}
