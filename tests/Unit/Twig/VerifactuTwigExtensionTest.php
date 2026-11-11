<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Twig;

use Nowo\VerifactuBundle\Model\AeatEnvironment;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Provider\AeatEnvironmentProviderInterface;
use Nowo\VerifactuBundle\Qr\QrCodeGenerator;
use Nowo\VerifactuBundle\Qr\QrUrlBuilder;
use Nowo\VerifactuBundle\Twig\VerifactuTwigExtension;
use PHPUnit\Framework\TestCase;

use function extension_loaded;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class VerifactuTwigExtensionTest extends TestCase
{
    public function testLegendAndQrHelpers(): void
    {
        $extension = new VerifactuTwigExtension(
            new QrCodeGenerator(new QrUrlBuilder()),
            new QrUrlBuilder(),
            'sandbox',
            ['legend' => 'verifactu', 'size_mm' => 35],
        );

        $record = new BillingRecord(
            RecordType::Alta,
            '89890001K',
            'FAC-001',
            '09-07-2026',
            'F1',
            '21.00',
            '121.00',
            '2026-07-09T16:00:00+02:00',
            hash: 'ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789',
        );

        self::assertStringStartsWith('https://', $extension->qrUrl($record));
        self::assertNotSame('', $extension->legend());

        if (!extension_loaded('gd')) {
            self::markTestSkipped('GD extension is required for QR PNG generation.');
        }

        self::assertStringStartsWith('data:image/png;base64,', $extension->qrDataUri($record));
    }

    public function testEnvironmentProviderIsAskedOnEveryCall(): void
    {
        $provider = $this->createMock(AeatEnvironmentProviderInterface::class);
        $provider->method('getEnvironment')->willReturnOnConsecutiveCalls(AeatEnvironment::Sandbox, AeatEnvironment::Production);
        $extension = new VerifactuTwigExtension(new QrCodeGenerator(new QrUrlBuilder()), new QrUrlBuilder(), 'sandbox', [], $provider);
        $record    = new BillingRecord(RecordType::Alta, '89890001K', 'FAC-001', '09-07-2026', 'F1', '21.00', '121.00', '2026-07-09T16:00:00+02:00');

        self::assertStringStartsWith('https://prewww2.aeat.es/', $extension->qrUrl($record));
        self::assertStringStartsWith('https://www2.agenciatributaria.gob.es/', $extension->qrUrl($record));
    }

    public function testGetFunctionsRegistersTwigHelpers(): void
    {
        $extension = new VerifactuTwigExtension(
            new QrCodeGenerator(new QrUrlBuilder()),
            new QrUrlBuilder(),
            'sandbox',
            ['legend' => 'verifactu', 'size_mm' => 35],
        );

        $names = array_map(static fn ($function) => $function->getName(), $extension->getFunctions());

        self::assertSame(
            ['verifactu_qr_data_uri', 'verifactu_qr_url', 'verifactu_legend'],
            $names,
        );
    }

    public function testLegendFallsBackToDefaultForUnknownValue(): void
    {
        $extension = new VerifactuTwigExtension(
            new QrCodeGenerator(new QrUrlBuilder()),
            new QrUrlBuilder(),
            'sandbox',
            ['legend' => 'unknown-value'],
        );

        self::assertSame(
            (new QrUrlBuilder())->buildLegend('verifactu'),
            $extension->legend(),
        );
    }
}
