<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Qr;

use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Generates PNG QR codes for AEAT invoice verification.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsAlias(id: self::SERVICE_NAME, public: true)]
final class QrCodeGenerator
{
    public const SERVICE_NAME = 'nowo_verifactu.qr.code_generator';

    public function __construct(
        private readonly QrUrlBuilder $urlBuilder,
    ) {
    }

    /**
     * Returns PNG binary data for the invoice QR code.
     *
     * @param array<string, mixed> $qrConfig
     */
    public function generatePng(BillingRecord $record, string $environment = 'sandbox', array $qrConfig = []): string
    {
        $url    = $this->urlBuilder->buildUrl($record, $environment);
        $sizeMm = (int) ($qrConfig['size_mm'] ?? 35);
        $sizePx = max(120, (int) round($sizeMm * 3.78));

        $qrCode = new QrCode(
            data: $url,
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $sizePx,
            margin: 0,
        );

        return (new PngWriter())->write($qrCode)->getString();
    }

    /**
     * Returns a data URI suitable for embedding in HTML or PDF templates.
     *
     * @param array<string, mixed> $qrConfig
     */
    public function generateDataUri(BillingRecord $record, string $environment = 'sandbox', array $qrConfig = []): string
    {
        $png = $this->generatePng($record, $environment, $qrConfig);

        return 'data:image/png;base64,' . base64_encode($png);
    }
}
