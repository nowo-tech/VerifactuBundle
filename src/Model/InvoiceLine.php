<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Model;

/**
 * Single invoice line for a billing record.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class InvoiceLine
{
    public function __construct(
        public readonly string $description,
        public readonly string $quantity,
        public readonly string $unitPrice,
        public readonly string $taxRate,
        public readonly string $taxAmount,
        public readonly string $totalAmount,
    ) {
    }
}
