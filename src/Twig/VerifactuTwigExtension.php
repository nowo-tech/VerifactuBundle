<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Twig;

use Nowo\VerifactuBundle\Model\AeatEnvironment;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Provider\AeatEnvironmentProviderInterface;
use Nowo\VerifactuBundle\Qr\QrCodeGenerator;
use Nowo\VerifactuBundle\Qr\QrUrlBuilder;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

use function in_array;

/**
 * Twig helpers for Veri*Factu QR codes and invoice legends.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class VerifactuTwigExtension extends AbstractExtension
{
    /**
     * Receives the QR services, the AEAT environment (or its runtime provider) and the QR configuration.
     *
     * @param array<string, mixed> $qrConfig
     */
    public function __construct(
        private readonly QrCodeGenerator $qrCodeGenerator,
        private readonly QrUrlBuilder $qrUrlBuilder,
        private readonly string $aeatEnvironment,
        private readonly array $qrConfig,
        private readonly ?AeatEnvironmentProviderInterface $environmentProvider = null,
    ) {
    }

    /**
     * Registers the `verifactu_qr_data_uri()`, `verifactu_qr_url()` and `verifactu_legend()` Twig functions.
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('verifactu_qr_data_uri', $this->qrDataUri(...), ['is_safe' => ['html']]),
            new TwigFunction('verifactu_qr_url', $this->qrUrl(...)),
            new TwigFunction('verifactu_legend', $this->legend(...)),
        ];
    }

    /**
     * Returns the QR code of the record as a PNG data URI.
     */
    public function qrDataUri(BillingRecord $record): string
    {
        return $this->qrCodeGenerator->generateDataUri($record, $record->environment ?? $this->environment(), $this->qrConfig);
    }

    /**
     * Returns the AEAT verification URL of the record.
     */
    public function qrUrl(BillingRecord $record): string
    {
        return $this->qrUrlBuilder->buildUrl($record, $record->environment ?? $this->environment());
    }

    /**
     * The AEAT environment of this call (the provider's when one is wired, else the configured one), for records that do
     * not carry the environment they were chained for.
     */
    private function environment(): AeatEnvironment
    {
        return $this->environmentProvider?->getEnvironment() ?? AeatEnvironment::fromValue($this->aeatEnvironment);
    }

    /**
     * Returns the mandatory invoice legend text.
     */
    public function legend(): string
    {
        $legend = (string) ($this->qrConfig['legend'] ?? 'verifactu');
        $legend = in_array($legend, ['verifactu', 'aeat_verifiable'], true) ? $legend : 'verifactu';

        return $this->qrUrlBuilder->buildLegend($legend);
    }
}
