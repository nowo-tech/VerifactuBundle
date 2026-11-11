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
 * Optional (1.6), each omitted from the XML when null, which keeps the output of earlier versions:
 * - $regimeKey: ClaveRegimen (AEAT list L8A for IVA, e.g. 01 general, 02 export, 17 OSS / IOSS). The AEAT warns when it
 *   is missing for IVA and rejects such records from 01/01/2027 (validations v1.2.2, §15.6).
 * - $qualification: CalificacionOperacion S1 (subject, not exempt; the default), S2 (reverse charge), N1 / N2 (not
 *   subject; N2 by location rules).
 * - $exemption: OperacionExenta E1 – E8, instead of a qualification.
 * For N1, N2 and exempt operations the XML has no TipoImpositivo nor CuotaRepercutida (validations §15.4 / §15.5); for
 * S2 both must be 0.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class TaxBreakdown
{
    /**
     * Creates one tax breakdown entry (DetalleDesglose).
     */
    public function __construct(
        public readonly string $taxType,
        public readonly string $taxRate,
        public readonly string $taxBase,
        public readonly string $taxAmount,
        public readonly ?string $regimeKey = null,
        public readonly ?string $qualification = null,
        public readonly ?string $exemption = null,
    ) {
    }
}
