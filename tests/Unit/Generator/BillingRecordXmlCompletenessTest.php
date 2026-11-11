<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Generator;

use Nowo\VerifactuBundle\Generator\BillingRecordXmlGenerator;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\Recipient;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Model\RectificationAmount;
use Nowo\VerifactuBundle\Model\RectifiedInvoice;
use Nowo\VerifactuBundle\Model\TaxBreakdown;
use Nowo\VerifactuBundle\Validator\XsdValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * V4 (1.6): every RegistroAlta field the AEAT needs for ordinary, rectifying, substitute and non-taxed invoices,
 * validated against the official SuministroInformacion.xsd shipped in src/Resources/schemas/aeat.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class BillingRecordXmlCompletenessTest extends TestCase
{
    /**
     * @return iterable<string, array{BillingRecord, list<string>}>
     */
    public static function invoices(): iterable
    {
        $spanish = Recipient::withNif('Cliente Nacional SL', 'B12345674');
        $eu      = Recipient::withForeignId('Kunde GmbH', Recipient::ID_TYPE_VAT_NUMBER, 'DE123456789', 'DE');
        $outside = Recipient::withForeignId('Customer Inc', Recipient::ID_TYPE_OTHER_DOCUMENT, 'US-998877', 'US');

        yield 'F1, domestic, general regime, recipient with NIF' => [
            self::record('F1', '21.00', '121.00', [new TaxBreakdown('01', '21.00', '100.00', '21.00', '01', 'S1')], [$spanish]),
            ['<ClaveRegimen>01</ClaveRegimen>', '<CalificacionOperacion>S1</CalificacionOperacion>', '<NIF>B12345674</NIF>'],
        ];

        yield 'F1, EU business customer, reverse charge (S2), OSS regime key' => [
            self::record('F1', '0.00', '100.00', [new TaxBreakdown('01', '0', '100.00', '0', '17', 'S2')], [$eu]),
            ['<ClaveRegimen>17</ClaveRegimen>', '<CalificacionOperacion>S2</CalificacionOperacion>', '<TipoImpositivo>0</TipoImpositivo>', '<CodigoPais>DE</CodigoPais>', '<IDType>02</IDType>'],
        ];

        yield 'F1, outside the EU, not subject (N2): no rate, no tax' => [
            self::record('F1', '0.00', '100.00', [new TaxBreakdown('01', '', '100.00', '', '01', 'N2')], [$outside]),
            ['<CalificacionOperacion>N2</CalificacionOperacion>', '<BaseImponibleOimporteNoSujeto>100.00</BaseImponibleOimporteNoSujeto>', '<IDType>06</IDType>'],
        ];

        yield 'F1, exempt (E1): OperacionExenta instead of CalificacionOperacion' => [
            self::record('F1', '0.00', '50.00', [new TaxBreakdown('01', '', '50.00', '', '01', null, 'E1')], [$spanish]),
            ['<OperacionExenta>E1</OperacionExenta>'],
        ];

        yield 'F1, several lines and a different operation date' => [
            self::record('F1', '31.00', '231.00', [
                new TaxBreakdown('01', '21.00', '100.00', '21.00', '01', 'S1'),
                new TaxBreakdown('01', '10.00', '100.00', '10.00', '01', 'S1'),
            ], [$spanish, $eu], operationDate: '30-06-2026'),
            ['<FechaOperacion>30-06-2026</FechaOperacion>', '<IDDestinatario>'],
        ];

        yield 'R1 by differences (I) with the rectified invoices' => [
            self::record('R1', '-21.00', '-121.00', [new TaxBreakdown('01', '21.00', '-100.00', '-21.00', '01', 'S1')], [$spanish], 'I', [
                new RectifiedInvoice('89890001K', 'FAC-2026-001', '01-07-2026'),
                new RectifiedInvoice('89890001K', 'FAC-2026-002', '02-07-2026'),
            ]),
            ['<TipoRectificativa>I</TipoRectificativa>', '<FacturasRectificadas>', '<NumSerieFactura>FAC-2026-002</NumSerieFactura>'],
        ];

        yield 'R1 by substitution (S) with ImporteRectificacion' => [
            self::record('R1', '21.00', '121.00', [new TaxBreakdown('01', '21.00', '100.00', '21.00', '01', 'S1')], [$spanish], 'S', [
                new RectifiedInvoice('89890001K', 'FAC-2026-001', '01-07-2026'),
            ], rectificationAmount: new RectificationAmount('100', '21', '0')),
            ['<TipoRectificativa>S</TipoRectificativa>', '<BaseRectificada>100.00</BaseRectificada>', '<CuotaRectificada>21.00</CuotaRectificada>', '<CuotaRecargoRectificado>0.00</CuotaRecargoRectificado>'],
        ];

        yield 'R5 (simplified rectifying invoice) without recipients' => [
            self::record('R5', '-2.10', '-12.10', [new TaxBreakdown('01', '21.00', '-10.00', '-2.10')], [], 'I', [
                new RectifiedInvoice('89890001K', 'T-2026-010', '03-07-2026'),
            ]),
            ['<TipoFactura>R5</TipoFactura>'],
        ];

        yield 'F3 replacing simplified invoices' => [
            self::record('F3', '21.00', '121.00', [new TaxBreakdown('01', '21.00', '100.00', '21.00', '01')], [$spanish], substitutedInvoices: [
                new RectifiedInvoice('89890001K', 'T-2026-011', '03-07-2026'),
            ]),
            ['<FacturasSustituidas>', '<IDFacturaSustituida>'],
        ];
    }

    /**
     * @param list<string> $expectedFragments
     */
    #[DataProvider('invoices')]
    public function testCompleteRecordsAreValidAgainstTheAeatSchema(BillingRecord $record, array $expectedFragments): void
    {
        $xml = (new BillingRecordXmlGenerator())->generate($record, ['name' => 'Demo Company SL'], self::software(), ['number' => '1']);

        self::assertSame([], (new XsdValidator(new IdentityTranslator(), true))->validate($xml, XsdValidator::SCHEMA_REGISTRO_ALTA));
        foreach ($expectedFragments as $fragment) {
            self::assertStringContainsString($fragment, $xml);
        }
    }

    public function testNotSubjectAndExemptLinesCarryNoRateNorTax(): void
    {
        $record = self::record('F1', '0.00', '100.00', [
            new TaxBreakdown('01', '21.00', '50.00', '10.50', null, 'N1'),
            new TaxBreakdown('01', '21.00', '50.00', '10.50', null, null, 'E2'),
        ], []);

        $xml = (new BillingRecordXmlGenerator())->generate($record, [], self::software(), ['number' => '1']);

        self::assertStringNotContainsString('<TipoImpositivo>', $xml);
        self::assertStringNotContainsString('<CuotaRepercutida>', $xml);
        self::assertStringNotContainsString('<CalificacionOperacion>E2', $xml);
    }

    public function testTheDefaultRegimeKeyFillsLinesWithoutOneAndTheDerivedLine(): void
    {
        $generator = new BillingRecordXmlGenerator('01');
        $withLine  = $generator->generate(self::record('F1', '21.00', '121.00', [new TaxBreakdown('', '21.00', '100.00', '21.00')], []), [], self::software(), []);
        $derived   = $generator->generate(self::record('F1', '21.00', '121.00', [], []), [], self::software(), []);
        $ownKey    = $generator->generate(self::record('F1', '21.00', '121.00', [new TaxBreakdown('', '21.00', '100.00', '21.00', '17')], []), [], self::software(), []);

        self::assertStringContainsString('<ClaveRegimen>01</ClaveRegimen>', $withLine);
        self::assertStringContainsString('<ClaveRegimen>01</ClaveRegimen>', $derived);
        self::assertStringContainsString('<ClaveRegimen>17</ClaveRegimen>', $ownKey);
    }

    public function testARecordWithoutTheNewFieldsKeepsTheOutputOf15(): void
    {
        $xml = (new BillingRecordXmlGenerator())->generate(self::record('F1', '21.00', '121.00', [new TaxBreakdown('01', '21.00', '100.00', '21.00')], []), [], self::software(), []);

        foreach (['ClaveRegimen', 'Destinatarios', 'TipoRectificativa', 'FacturasRectificadas', 'FacturasSustituidas', 'ImporteRectificacion', 'FechaOperacion', 'OperacionExenta'] as $element) {
            self::assertStringNotContainsString('<' . $element . '>', $xml);
        }
        self::assertStringContainsString("<Impuesto>01</Impuesto>\n      <CalificacionOperacion>S1</CalificacionOperacion>\n      <TipoImpositivo>21.00</TipoImpositivo>", $xml);
    }

    /**
     * @param list<TaxBreakdown> $breakdown
     * @param list<Recipient> $recipients
     * @param list<RectifiedInvoice> $rectified
     * @param list<RectifiedInvoice> $substitutedInvoices
     */
    private static function record(
        string $type,
        string $tax,
        string $total,
        array $breakdown,
        array $recipients,
        ?string $rectificationType = null,
        array $rectified = [],
        ?RectificationAmount $rectificationAmount = null,
        ?string $operationDate = null,
        array $substitutedInvoices = [],
    ): BillingRecord {
        return new BillingRecord(
            RecordType::Alta,
            '89890001K',
            'FAC-2026-100',
            '09-07-2026',
            $type,
            $tax,
            $total,
            '2026-07-09T16:00:00+02:00',
            taxBreakdown: $breakdown,
            hash: str_repeat('A', 64),
            issuerName: 'Demo Company SL',
            operationDescription: 'Services',
            recipients: $recipients,
            rectificationType: $rectificationType,
            rectifiedInvoices: $rectified,
            substitutedInvoices: $substitutedInvoices,
            rectificationAmount: $rectificationAmount,
            operationDate: $operationDate,
        );
    }

    /**
     * @return array<string, string>
     */
    private static function software(): array
    {
        return ['manufacturer_name' => 'Nowo.tech', 'manufacturer_nif' => '89890001K', 'name' => 'Beacon', 'id' => 'SB', 'version' => '1.0.0'];
    }
}
