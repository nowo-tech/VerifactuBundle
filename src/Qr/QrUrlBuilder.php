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

    private const SANDBOX_HOST    = 'https://prewww2.aeat.es/wlpl/TIKE-CONT/';
    private const PRODUCTION_HOST = 'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/';

    /** Path for invoices issued by a VERI*FACTU system (AEAT QR specification §5.1). */
    private const PATH_VERIFACTU = 'ValidarQR';

    /** Path for invoices issued by a non-VERI*FACTU system (AEAT QR specification §5.2). */
    private const PATH_NO_VERIFACTU = 'ValidarQRNoVerifactu';

    /**
     * @param string $mode Default bundle mode (verifactu|no_verifactu), wired from nowo_verifactu.mode
     */
    public function __construct(
        private readonly string $mode = 'verifactu',
    ) {
    }

    /**
     * @param string|null $mode verifactu|no_verifactu; null uses the configured bundle mode
     */
    public function buildUrl(BillingRecord $record, string $environment = 'sandbox', ?string $mode = null): string
    {
        $host    = $environment === 'production' ? self::PRODUCTION_HOST : self::SANDBOX_HOST;
        $path    = ($mode ?? $this->mode) === 'no_verifactu' ? self::PATH_NO_VERIFACTU : self::PATH_VERIFACTU;
        $baseUrl = $host . $path;

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
