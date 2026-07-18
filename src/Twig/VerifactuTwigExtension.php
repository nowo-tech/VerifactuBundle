<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Twig;

use Nowo\VerifactuBundle\Model\BillingRecord;
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
     * @param array<string, mixed> $qrConfig
     */
    public function __construct(
        private readonly QrCodeGenerator $qrCodeGenerator,
        private readonly QrUrlBuilder $qrUrlBuilder,
        private readonly string $aeatEnvironment,
        private readonly array $qrConfig,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('verifactu_qr_data_uri', $this->qrDataUri(...), ['is_safe' => ['html']]),
            new TwigFunction('verifactu_qr_url', $this->qrUrl(...)),
            new TwigFunction('verifactu_legend', $this->legend(...)),
        ];
    }

    public function qrDataUri(BillingRecord $record): string
    {
        return $this->qrCodeGenerator->generateDataUri($record, $this->aeatEnvironment, $this->qrConfig);
    }

    public function qrUrl(BillingRecord $record): string
    {
        return $this->qrUrlBuilder->buildUrl($record, $this->aeatEnvironment);
    }

    public function legend(): string
    {
        $legend = (string) ($this->qrConfig['legend'] ?? 'verifactu');
        $legend = in_array($legend, ['verifactu', 'aeat_verifiable'], true) ? $legend : 'verifactu';

        return $this->qrUrlBuilder->buildLegend($legend);
    }
}
