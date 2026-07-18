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
    public function getLastState(string $issuerNif): ?HashChainState;

    public function storeLastState(HashChainState $state): void;

    public function reset(string $issuerNif): void;

    /**
     * @deprecated Use getLastState() instead
     */
    public function getLastHash(string $issuerNif): ?string;

    /**
     * @deprecated Use storeLastState() instead
     */
    public function storeLastHash(string $issuerNif, string $hash): void;
}
