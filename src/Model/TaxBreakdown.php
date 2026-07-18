<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Model;

/**
 * Tax breakdown entry for a billing record.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class TaxBreakdown
{
    public function __construct(
        public readonly string $taxType,
        public readonly string $taxRate,
        public readonly string $taxBase,
        public readonly string $taxAmount,
    ) {
    }
}
