<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Qr;

use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Qr\QrUrlBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class QrUrlBuilderTest extends TestCase
{
    private QrUrlBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new QrUrlBuilder();
    }

    public function testBuildSandboxUrl(): void
    {
        $record = new BillingRecord(
            RecordType::Alta,
            '89890001K',
            'FAC-001',
            '09-07-2026',
            'F1',
            '21.00',
            '121.00',
            '2026-07-09T16:00:00+02:00',
        );

        $url = $this->builder->buildUrl($record, 'sandbox');

        self::assertStringStartsWith('https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR?', $url);
        self::assertStringContainsString('nif=89890001K', $url);
        self::assertStringContainsString('numserie=FAC-001', $url);
    }

    public function testBuildUrlUsesNoVerifactuPathForNoVerifactuMode(): void
    {
        $record = $this->createRecord();

        self::assertStringStartsWith(
            'https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQRNoVerifactu?nif=89890001K&numserie=FAC-001',
            (new QrUrlBuilder('no_verifactu'))->buildUrl($record),
        );
        self::assertStringStartsWith(
            'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQRNoVerifactu?',
            $this->builder->buildUrl($record, 'production', 'no_verifactu'),
        );
    }

    public function testBuildUrlPerCallModeOverridesConfiguredMode(): void
    {
        $record = $this->createRecord();

        self::assertSame(
            'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR?nif=89890001K&numserie=FAC-001&fecha=09-07-2026&importe=121.00',
            (new QrUrlBuilder('no_verifactu'))->buildUrl($record, 'production', 'verifactu'),
        );
    }

    public function testBuildLegend(): void
    {
        self::assertSame('VERI*FACTU', $this->builder->buildLegend('verifactu'));
        self::assertSame(
            'Factura verificable en la sede electrónica de la AEAT',
            $this->builder->buildLegend('aeat_verifiable'),
        );
    }

    private function createRecord(): BillingRecord
    {
        return new BillingRecord(
            RecordType::Alta,
            '89890001K',
            'FAC-001',
            '09-07-2026',
            'F1',
            '21.00',
            '121.00',
            '2026-07-09T16:00:00+02:00',
        );
    }
}
