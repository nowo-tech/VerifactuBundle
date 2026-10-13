<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Generator;

use Nowo\VerifactuBundle\Generator\HashChainGenerator;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use PHPUnit\Framework\TestCase;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class HashChainGeneratorTest extends TestCase
{
    private HashChainGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new HashChainGenerator();
    }

    public function testBuildAltaInputStringMatchesAeatExample(): void
    {
        $record = $this->createAltaRecord();

        self::assertSame(
            'IDEmisorFactura=89890001K&NumSerieFactura=12345678/G33&FechaExpedicionFactura=01-01-2024&TipoFactura=F1&CuotaTotal=12.35&ImporteTotal=123.45&Huella=&FechaHoraHusoGenRegistro=2024-01-01T19:20:30+01:00',
            $this->generator->buildInputString($record),
        );
    }

    public function testComputeHashMatchesAeatExample(): void
    {
        $record = $this->createAltaRecord();

        self::assertSame(
            '3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60',
            $this->generator->computeHash($record),
        );
    }

    public function testVerifyHashReturnsTrueForValidHash(): void
    {
        $record = $this->createAltaRecord();

        self::assertTrue(
            $this->generator->verifyHash($record, '3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60'),
        );
    }

    public function testVerifyChainLinkDetectsMismatch(): void
    {
        $record = $this->createAltaRecord()->withHash('ABC', 'PREVIOUS');

        self::assertFalse($this->generator->verifyChainLink($record, 'OTHER'));
        self::assertTrue($this->generator->verifyChainLink($record, 'PREVIOUS'));
    }

    public function testVerifyChainLinkAcceptsEmptyPreviousHashForFirstRecord(): void
    {
        $record = $this->createAltaRecord();

        self::assertTrue($this->generator->verifyChainLink($record, null));
    }

    public function testBuildInputStringNormalizesNumericAmounts(): void
    {
        $record = new BillingRecord(
            RecordType::Alta,
            '89890001K',
            'FAC-001',
            '09-07-2026',
            'F1',
            '21.000000',
            '121.000000',
            '2026-07-09T16:00:00+02:00',
        );

        self::assertStringContainsString('CuotaTotal=21&ImporteTotal=121', $this->generator->buildInputString($record));
    }

    public function testBuildAnulacionInputStringUsesReducedFieldSet(): void
    {
        $record = new BillingRecord(
            RecordType::Anulacion,
            '89890001K',
            'FAC-001',
            '09-07-2026',
            'F1',
            '21.00',
            '121.00',
            '2026-07-09T16:00:00+02:00',
            previousHash: 'PREVIOUSHASH',
        );

        self::assertSame(
            'IDEmisorFacturaAnulada=89890001K&NumSerieFacturaAnulada=FAC-001&FechaExpedicionFacturaAnulada=09-07-2026&Huella=PREVIOUSHASH&FechaHoraHusoGenRegistro=2026-07-09T16:00:00+02:00',
            $this->generator->buildInputString($record),
        );
    }

    public function testComputeHashMatchesAeatSecondAltaExample(): void
    {
        $record = new BillingRecord(
            RecordType::Alta,
            '89890001K',
            '12345679/G34',
            '01-01-2024',
            'F1',
            '12.35',
            '123.45',
            '2024-01-01T19:20:35+01:00',
            previousHash: '3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60',
        );

        self::assertSame(
            'F7B94CFD8924EDFF273501B01EE5153E4CE8F259766F88CF6ACB8935802A2B97',
            $this->generator->computeHash($record),
        );
    }

    /**
     * Official AEAT test vector (hash specification v0.1.2, §6.3: anulación with previous record).
     */
    public function testComputeHashMatchesAeatAnulacionExample(): void
    {
        $record = new BillingRecord(
            RecordType::Anulacion,
            '89890001K',
            '12345679/G34',
            '01-01-2024',
            'F1',
            '12.35',
            '123.45',
            '2024-01-01T19:20:40+01:00',
            previousHash: 'F7B94CFD8924EDFF273501B01EE5153E4CE8F259766F88CF6ACB8935802A2B97',
        );

        self::assertSame(
            'IDEmisorFacturaAnulada=89890001K&NumSerieFacturaAnulada=12345679/G34&FechaExpedicionFacturaAnulada=01-01-2024&Huella=F7B94CFD8924EDFF273501B01EE5153E4CE8F259766F88CF6ACB8935802A2B97&FechaHoraHusoGenRegistro=2024-01-01T19:20:40+01:00',
            $this->generator->buildInputString($record),
        );
        self::assertSame(
            '177547C0D57AC74748561D054A9CEC14B4C4EA23D1BEFD6F2E69E3A388F90C68',
            $this->generator->computeHash($record),
        );
    }

    public function testBuildInputStringDoesNotRoundLargeAmountsThroughFloat(): void
    {
        $record = new BillingRecord(
            RecordType::Alta,
            '89890001K',
            'FAC-001',
            '09-07-2026',
            'F1',
            '-0012.50',
            '999999999999.99',
            '2026-07-09T16:00:00+02:00',
        );

        self::assertStringContainsString('CuotaTotal=-12.5&ImporteTotal=999999999999.99', $this->generator->buildInputString($record));
    }

    public function testTrailingZerosDoNotChangeTheHash(): void
    {
        $short = new BillingRecord(RecordType::Alta, '89890001K', 'FAC-001', '09-07-2026', 'F1', '21', '123.1', '2026-07-09T16:00:00+02:00');
        $long  = new BillingRecord(RecordType::Alta, '89890001K', 'FAC-001', '09-07-2026', 'F1', '21.00', '123.10', '2026-07-09T16:00:00+02:00');

        self::assertSame($this->generator->computeHash($short), $this->generator->computeHash($long));
    }

    public function testBuildInputStringKeepsEmptyAmountsUntouched(): void
    {
        $record = new BillingRecord(
            RecordType::Alta,
            '89890001K',
            'FAC-001',
            '09-07-2026',
            'F1',
            '',
            '',
            '2026-07-09T16:00:00+02:00',
        );

        self::assertStringContainsString('CuotaTotal=&ImporteTotal=', $this->generator->buildInputString($record));
    }

    public function testBuildInputStringKeepsNonNumericAmountsUntouched(): void
    {
        $record = new BillingRecord(
            RecordType::Alta,
            '89890001K',
            'FAC-001',
            '09-07-2026',
            'F1',
            '1e3',
            'pending',
            '2026-07-09T16:00:00+02:00',
        );

        self::assertStringContainsString('CuotaTotal=1e3&ImporteTotal=pending', $this->generator->buildInputString($record));
    }

    private function createAltaRecord(): BillingRecord
    {
        return new BillingRecord(
            RecordType::Alta,
            '89890001K',
            '12345678/G33',
            '01-01-2024',
            'F1',
            '12.35',
            '123.45',
            '2024-01-01T19:20:30+01:00',
        );
    }
}
