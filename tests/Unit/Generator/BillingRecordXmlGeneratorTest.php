<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Generator;

use Nowo\VerifactuBundle\Generator\BillingRecordXmlGenerator;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Validator\XsdValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class BillingRecordXmlGeneratorTest extends TestCase
{
    private BillingRecordXmlGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new BillingRecordXmlGenerator();
    }

    public function testGenerateAltaXmlContainsMandatoryFields(): void
    {
        $record = new BillingRecord(
            RecordType::Alta,
            '89890001K',
            'FAC-2026-001',
            '09-07-2026',
            'F1',
            '21.00',
            '121.00',
            '2026-07-09T16:00:00+02:00',
            hash: 'ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789',
            issuerName: 'Demo Company SL',
            operationDescription: 'Demo invoice',
        );

        $xml = $this->generator->generate(
            $record,
            ['name' => 'Demo Company SL'],
            [
                'manufacturer_name' => 'Nowo.tech',
                'manufacturer_nif'  => '89890001K',
                'name'              => 'VerifactuBundle',
                'id'                => '01',
                'version'           => '1.0.0',
            ],
            ['number' => '001'],
        );

        self::assertStringContainsString('<RegistroAlta xmlns="' . BillingRecordXmlGenerator::NS_SUMINISTRO . '">', $xml);
        self::assertStringContainsString('<IDVersion>1.0</IDVersion>', $xml);
        self::assertStringContainsString('<NombreRazonEmisor>Demo Company SL</NombreRazonEmisor>', $xml);
        self::assertStringContainsString('<DescripcionOperacion>Demo invoice</DescripcionOperacion>', $xml);
        self::assertStringContainsString('<PrimerRegistro>S</PrimerRegistro>', $xml);
        self::assertStringContainsString('<TipoUsoPosibleSoloVerifactu>S</TipoUsoPosibleSoloVerifactu>', $xml);
    }

    public function testGeneratedAltaXmlPassesXsdValidation(): void
    {
        $record = new BillingRecord(
            RecordType::Alta,
            '89890001K',
            'FAC-2026-001',
            '09-07-2026',
            'F1',
            '21.00',
            '121.00',
            '2026-07-09T16:00:00+02:00',
            hash: 'ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789',
            issuerName: 'Demo Company SL',
            operationDescription: 'Demo invoice',
        );

        $xml = $this->generator->generate(
            $record,
            ['name' => 'Demo Company SL'],
            [
                'manufacturer_name' => 'Nowo.tech',
                'manufacturer_nif'  => '89890001K',
                'name'              => 'VerifactuBundle',
                'id'                => '01',
                'version'           => '1.0.0',
            ],
            ['number' => '001'],
        );

        $validator = new XsdValidator(new IdentityTranslator(), true);
        self::assertSame([], $validator->validate($xml, XsdValidator::SCHEMA_REGISTRO_ALTA));
    }

    public function testGeneratedAnulacionXmlPassesXsdValidation(): void
    {
        $record = new BillingRecord(
            RecordType::Anulacion,
            '89890001K',
            'FAC-2026-001',
            '09-07-2026',
            'F1',
            '21.00',
            '121.00',
            '2026-07-09T18:00:00+02:00',
            hash: 'FEDCBA9876543210FEDCBA9876543210FEDCBA9876543210FEDCBA9876543210',
        );

        $xml = $this->generator->generate(
            $record,
            ['name' => 'Demo Company SL'],
            [
                'manufacturer_name' => 'Nowo.tech',
                'manufacturer_nif'  => '89890001K',
                'name'              => 'VerifactuBundle',
                'id'                => '01',
                'version'           => '1.0.0',
            ],
            ['number' => '001'],
        );

        self::assertStringContainsString('<IDEmisorFacturaAnulada>89890001K</IDEmisorFacturaAnulada>', $xml);

        $validator = new XsdValidator(new IdentityTranslator(), true);
        self::assertSame([], $validator->validate($xml, XsdValidator::SCHEMA_REGISTRO_ANULACION));
    }

    public function testGenerateAltaXmlWithPreviousStateUsesRegistroAnterior(): void
    {
        $record = new BillingRecord(
            RecordType::Alta,
            '89890001K',
            'FAC-2026-002',
            '09-07-2026',
            'F1',
            '21.00',
            '121.00',
            '2026-07-09T17:00:00+02:00',
            hash: 'FEDCBA9876543210FEDCBA9876543210FEDCBA9876543210FEDCBA9876543210',
            issuerName: 'Demo Company SL',
            operationDescription: 'Chained invoice',
        );

        $xml = $this->generator->generate(
            $record,
            ['name' => 'Demo Company SL'],
            [
                'manufacturer_name' => 'Nowo.tech',
                'manufacturer_nif'  => '89890001K',
                'name'              => 'VerifactuBundle',
                'id'                => '01',
                'version'           => '1.0.0',
                'solo_verifactu'    => false,
            ],
            ['number' => '001'],
            new \Nowo\VerifactuBundle\Model\HashChainState(
                '89890001K',
                'FAC-2026-001',
                '09-07-2026',
                'ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789',
            ),
        );

        self::assertStringContainsString('<RegistroAnterior>', $xml);
        self::assertStringContainsString('<TipoUsoPosibleSoloVerifactu>N</TipoUsoPosibleSoloVerifactu>', $xml);
    }

    public function testGenerateAltaXmlUsesTaxBreakdownWhenProvided(): void
    {
        $record = new BillingRecord(
            RecordType::Alta,
            '89890001K',
            'FAC-2026-003',
            '09-07-2026',
            'F1',
            '21.00',
            '121.00',
            '2026-07-09T18:00:00+02:00',
            taxBreakdown: [new \Nowo\VerifactuBundle\Model\TaxBreakdown('01', '21.00', '100.00', '21.00')],
            hash: 'ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789',
            issuerName: 'Demo Company SL',
        );

        $xml = $this->generator->generate(
            $record,
            ['name' => 'Demo Company SL'],
            [
                'manufacturer_name' => 'Nowo.tech',
                'manufacturer_nif'  => '89890001K',
                'name'              => 'VerifactuBundle',
                'id'                => '01',
                'version'           => '1.0.0',
            ],
            ['number' => '001'],
        );

        self::assertStringContainsString('<TipoImpositivo>21.00</TipoImpositivo>', $xml);
    }

    public function testGenerateAltaXmlHandlesNonNumericAmounts(): void
    {
        $record = new BillingRecord(
            RecordType::Alta,
            '89890001K',
            'FAC-2026-004',
            '09-07-2026',
            'F1',
            'invalid',
            'invalid',
            '2026-07-09T19:00:00+02:00',
            hash: 'ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789',
            issuerName: 'Demo Company SL',
        );

        $xml = $this->generator->generate(
            $record,
            ['name' => 'Demo Company SL'],
            [
                'manufacturer_name' => 'Nowo.tech',
                'manufacturer_nif'  => '89890001K',
                'name'              => 'VerifactuBundle',
                'id'                => '01',
                'version'           => '1.0.0',
            ],
            ['number' => '001'],
        );

        self::assertStringContainsString('<BaseImponibleOimporteNoSujeto>0.00</BaseImponibleOimporteNoSujeto>', $xml);
    }
}
