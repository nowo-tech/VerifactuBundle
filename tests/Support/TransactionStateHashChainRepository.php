<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Support;

use Nowo\VerifactuBundle\Exception\ChainTransactionLostException;
use Nowo\VerifactuBundle\Model\HashChainState;
use Nowo\VerifactuBundle\Repository\InMemoryHashChainRepository;
use Nowo\VerifactuBundle\Repository\LockableHashChainRepositoryInterface;
use Nowo\VerifactuBundle\Repository\TransactionStateAwareInterface;

use function in_array;

/**
 * In-memory lockable repository that reports a configurable transaction state (host transaction open or not).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class TransactionStateHashChainRepository implements LockableHashChainRepositoryInterface, TransactionStateAwareInterface
{
    private readonly InMemoryHashChainRepository $inner;

    /** Number of setRollbackOnly() calls so far. */
    public int $rollbackOnlyMarks = 0;

    /** Number of verifyHeadInTransaction() calls so far. */
    public int $verifications = 0;

    /**
     * @param list<int> $lostOnVerification the verifyHeadInTransaction() calls (1-based) that find the head gone
     */
    public function __construct(public bool $insideTransaction, public array $lostOnVerification = [])
    {
        $this->inner = new InMemoryHashChainRepository();
    }

    public function isInsideTransaction(): bool
    {
        return $this->insideTransaction;
    }

    public function setRollbackOnly(): void
    {
        ++$this->rollbackOnlyMarks;
    }

    public function verifyHeadInTransaction(string $issuerNif, string $hash): void
    {
        ++$this->verifications;
        if (in_array($this->verifications, $this->lostOnVerification, true)) {
            throw new ChainTransactionLostException();
        }
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
