<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Model;

/**
 * Outcome of ChainVerifier::verify() for one issuer.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class ChainVerificationResult
{
    /**
     * Creates the result.
     *
     * @param list<array{position: int, invoiceSeriesNumber: string, problem: string}> $problems
     * @param string|null $continuesFrom the previous hash of the first stored
     *                                   record when the log starts in the
     *                                   middle of an older chain
     */
    public function __construct(
        public readonly string $issuerNif,
        public readonly int $checkedRecords,
        public readonly array $problems = [],
        public readonly ?string $continuesFrom = null,
    ) {
    }

    /**
     * Whether every record hash, chain link and the chain head are consistent.
     */
    public function isValid(): bool
    {
        return $this->problems === [];
    }
}
