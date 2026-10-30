<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Repository;

use Nowo\VerifactuBundle\Model\HashChainState;

/**
 * Persists the last billing record state per issuer for chain linking.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
interface HashChainRepositoryInterface
{
    /**
     * Returns the current chain head (last record state) of the issuer, or null when the chain is empty.
     */
    public function getLastState(string $issuerNif): ?HashChainState;

    /**
     * Stores the new chain head of the issuer.
     */
    public function storeLastState(HashChainState $state): void;

    /**
     * Removes the chain head of the issuer (the next record starts a new chain).
     */
    public function reset(string $issuerNif): void;

    /**
     * Returns the hash of the current chain head of the issuer (deprecated: use getLastState()).
     *
     * @deprecated Use getLastState() instead
     */
    public function getLastHash(string $issuerNif): ?string;

    /**
     * Stores only a hash as chain head of the issuer (deprecated: use storeLastState()).
     *
     * @deprecated Use storeLastState() instead
     */
    public function storeLastHash(string $issuerNif, string $hash): void;
}
