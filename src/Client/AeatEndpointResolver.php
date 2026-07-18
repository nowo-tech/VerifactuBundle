<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Client;

/**
 * Resolves AEAT SOAP endpoints for Veri*Factu and No-Veri*Factu modes.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class AeatEndpointResolver
{
    public const SOAP_ACTION_ALTA = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SistemaFacturacion/altaRegistroFactura';

    public function resolve(string $mode, string $environment, string $certificateType = 'personal'): string
    {
        $isSandbox = $environment === 'sandbox';
        $isSeal    = $certificateType === 'seal';

        if ($mode === 'no_verifactu') {
            if ($isSandbox) {
                return $isSeal
                    ? 'https://prewww10.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/RequerimientoSOAP'
                    : 'https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/RequerimientoSOAP';
            }

            return $isSeal
                ? 'https://www10.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/RequerimientoSOAP'
                : 'https://www1.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/RequerimientoSOAP';
        }

        if ($isSandbox) {
            return $isSeal
                ? 'https://prewww10.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP'
                : 'https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP';
        }

        return $isSeal
            ? 'https://www10.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP'
            : 'https://www1.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP';
    }
}
