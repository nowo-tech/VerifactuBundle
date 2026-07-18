<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Qr;

use Nowo\VerifactuBundle\Model\BillingRecord;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use const PHP_QUERY_RFC3986;

/**
 * Builds AEAT invoice verification QR URLs.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsAlias(id: self::SERVICE_NAME, public: true)]
final class QrUrlBuilder
{
    public const SERVICE_NAME = 'nowo_verifactu.qr.url_builder';

    private const SANDBOX_BASE_URL    = 'https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR';
    private const PRODUCTION_BASE_URL = 'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR';

    public function buildUrl(BillingRecord $record, string $environment = 'sandbox'): string
    {
        $baseUrl = $environment === 'production' ? self::PRODUCTION_BASE_URL : self::SANDBOX_BASE_URL;

        $query = http_build_query([
            'nif'      => trim($record->issuerNif),
            'numserie' => trim($record->invoiceSeriesNumber),
            'fecha'    => trim($record->issueDate),
            'importe'  => trim($record->totalAmount),
        ], '', '&', PHP_QUERY_RFC3986);

        return $baseUrl . '?' . $query;
    }

    /**
     * Returns the mandatory invoice legend text.
     *
     * @param 'aeat_verifiable'|'verifactu' $legend
     */
    public function buildLegend(string $legend = 'verifactu'): string
    {
        return match ($legend) {
            'aeat_verifiable' => 'Factura verificable en la sede electrónica de la AEAT',
            default           => 'VERI*FACTU',
        };
    }
}
