<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Repository;

use Nowo\VerifactuBundle\Model\HashChainState;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * In-memory hash chain storage for development and demos.
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

    public function getLastState(string $issuerNif): ?HashChainState
    {
        return $this->states[$this->normalizeKey($issuerNif)] ?? null;
    }

    public function storeLastState(HashChainState $state): void
    {
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
