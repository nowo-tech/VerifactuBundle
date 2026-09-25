<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Repository;

use Nowo\VerifactuBundle\Model\HashChainState;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * In-memory hash chain storage for development and demos.
 *
 * The storage is request-scoped: it is cleared at the start of every main request
 * (see WorkerStateResetSubscriber) and on kernel.reset, so long-running workers
 * (FrankenPHP worker mode) never build a per-worker chain. It is not a legally valid
 * Veri*Factu chain store; use hash_chain.storage: doctrine (or a custom repository) in production.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsAlias(id: self::SERVICE_NAME, public: true)]
final class InMemoryHashChainRepository implements HashChainRepositoryInterface
{
    public const SERVICE_NAME = 'nowo_verifactu.repository.in_memory_hash_chain';

    /** @var array<string, HashChainState> */
    private array $states = [];

    /**
     * @param bool $warnNotPersistent Log a warning on every store (enabled when kernel.debug is false)
     */
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $warnNotPersistent = false,
    ) {
    }

    public function getLastState(string $issuerNif): ?HashChainState
    {
        return $this->states[$this->normalizeKey($issuerNif)] ?? null;
    }

    public function storeLastState(HashChainState $state): void
    {
        if ($this->warnNotPersistent) {
            $this->logger?->warning(
                'Veri*Factu hash chain stored in memory for issuer "{issuer}": the chain is lost at the end of the request. Configure nowo_verifactu.hash_chain.storage: doctrine or a persistent hash_chain.repository.',
                ['issuer' => $this->normalizeKey($state->issuerNif)],
            );
        }

        $this->states[$this->normalizeKey($state->issuerNif)] = new HashChainState(
            $state->issuerNif,
            $state->invoiceSeriesNumber,
            $state->issueDate,
            strtoupper($state->hash),
        );
    }

    public function reset(string $issuerNif): void
    {
        unset($this->states[$this->normalizeKey($issuerNif)]);
    }

    /**
     * Drops the state of every issuer (called on kernel.reset and at the start of each main request).
     */
    public function clear(): void
    {
        $this->states = [];
    }

    public function getLastHash(string $issuerNif): ?string
    {
        return $this->getLastState($issuerNif)?->hash;
    }

    public function storeLastHash(string $issuerNif, string $hash): void
    {
        $existing = $this->getLastState($issuerNif);
        $this->storeLastState(new HashChainState(
            $issuerNif,
            $existing instanceof HashChainState ? $existing->invoiceSeriesNumber : '',
            $existing instanceof HashChainState ? $existing->issueDate : '',
            $hash,
        ));
    }

    private function normalizeKey(string $issuerNif): string
    {
        return strtoupper(trim($issuerNif));
    }
}
