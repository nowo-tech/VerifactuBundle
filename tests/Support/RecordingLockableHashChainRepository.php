<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Support;

use Nowo\VerifactuBundle\Model\HashChainState;
use Nowo\VerifactuBundle\Repository\InMemoryHashChainRepository;
use Nowo\VerifactuBundle\Repository\LockableHashChainRepositoryInterface;

/**
 * Lockable repository test double that records the order of lock / read / write calls.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class RecordingLockableHashChainRepository implements LockableHashChainRepositoryInterface
{
    /** @var list<string> */
    public array $calls = [];

    private readonly InMemoryHashChainRepository $inner;

    public function __construct()
    {
        $this->inner = new InMemoryHashChainRepository();
    }

    public function withChainLock(string $issuerNif, callable $operation): mixed
    {
        $this->calls[] = 'lock:' . $issuerNif;

        try {
            return $operation();
        } finally {
            $this->calls[] = 'unlock:' . $issuerNif;
        }
    }

    public function getLastState(string $issuerNif): ?HashChainState
    {
        $this->calls[] = 'get';

        return $this->inner->getLastState($issuerNif);
    }

    public function storeLastState(HashChainState $state): void
    {
        $this->calls[] = 'store';
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
