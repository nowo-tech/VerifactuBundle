<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Model;

/**
 * Tax breakdown entry for a billing record.
 *
 * $taxType is emitted as DetalleDesglose/Impuesto and must be an AEAT ImpuestoType code:
 * 01 (IVA), 02 (IPSI Ceuta y Melilla), 03 (IGIC) or 05 (Otros). An empty string omits the element
 * (AEAT then assumes 01).
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
