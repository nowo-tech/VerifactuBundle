<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Support;

use Nowo\VerifactuBundle\Model\HashChainState;
use Nowo\VerifactuBundle\Repository\InMemoryHashChainRepository;
use Nowo\VerifactuBundle\Repository\LockableHashChainRepositoryInterface;
use Nowo\VerifactuBundle\Repository\TransactionStateAwareInterface;

/**
 * In-memory lockable repository that reports a configurable transaction state (host transaction open or not).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class TransactionStateHashChainRepository implements LockableHashChainRepositoryInterface, TransactionStateAwareInterface
{
    private readonly InMemoryHashChainRepository $inner;

    public function __construct(public bool $insideTransaction)
    {
        $this->inner = new InMemoryHashChainRepository();
    }

    public function isInsideTransaction(): bool
    {
        return $this->insideTransaction;
    }

    public function withChainLock(string $issuerNif, callable $operation): mixed
    {
        return $operation($this->inner->getLastState($issuerNif));
    }

    public function getLastState(string $issuerNif): ?HashChainState
    {
        return $this->inner->getLastState($issuerNif);
    }

    public function storeLastState(HashChainState $state): void
    {
        $this->inner->storeLastState($state);
    }

    public function reset(string $issuerNif): void
    {
        $this->inner->reset($issuerNif);
    }

    public function getLastHash(string $issuerNif): ?string
    {
        return $this->inner->getLastHash($issuerNif);
    }

    public function storeLastHash(string $issuerNif, string $hash): void
    {
        $this->inner->storeLastHash($issuerNif, $hash);
    }
}
