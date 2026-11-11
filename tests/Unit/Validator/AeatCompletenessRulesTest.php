<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Validator;

use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\Recipient;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Model\RectificationAmount;
use Nowo\VerifactuBundle\Model\RectifiedInvoice;
use Nowo\VerifactuBundle\Model\TaxBreakdown;
use Nowo\VerifactuBundle\Validator\AeatBusinessRulesValidator;
use Nowo\VerifactuBundle\Validator\SpanishTaxIdValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * Rules on the 1.6 fields (AEAT "Validaciones y errores" v1.2.2, §3.1.3 items 3 – 7, 13 and 15).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class AeatCompletenessRulesTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function cases(): iterable
    {
        $customer = Recipient::withNif('Cliente SL', 'B12345674');
        $invoice  = new RectifiedInvoice('89890001K', 'FAC-1', '01-07-2026');

        yield 'valid complete F1' => [['recipients' => [$customer], 'operationDate' => '30-06-2026', 'taxBreakdown' => [new TaxBreakdown('01', '21', '100', '21', '01', 'S1')]], []];
        yield 'valid R1 by substitution' => [['invoiceType' => 'R1', 'recipients' => [$customer], 'rectificationType' => 's', 'rectifiedInvoices' => [$invoice], 'rectificationAmount' => new RectificationAmount('100', '21', '0')], []];
        yield 'rectification type on an F1' => [['rectificationType' => 'I'], ['validation.rectification_type.invalid']];
        yield 'unknown rectification type' => [['invoiceType' => 'R1', 'rectificationType' => 'X'], ['validation.rectification_type.invalid']];
        yield 'rectified invoices on an F1' => [['rectifiedInvoices' => [$invoice]], ['validation.rectified_invoices.not_allowed']];
        yield 'substituted invoices on an F1' => [['substitutedInvoices' => [$invoice]], ['validation.substituted_invoices.not_allowed']];
        yield 'invalid referenced invoice' => [['invoiceType' => 'R2', 'rectificationType' => 'I', 'rectifiedInvoices' => [new RectifiedInvoice('BAD', '', '31-02-2026')]], ['validation.invoice_reference.invalid']];
        yield 'S without amounts' => [['invoiceType' => 'R1', 'rectificationType' => 'S'], ['validation.rectification_amount.invalid']];
        yield 'amounts with I' => [['invoiceType' => 'R1', 'rectificationType' => 'I', 'rectificationAmount' => new RectificationAmount('1', '1')], ['validation.rectification_amount.invalid']];
        yield 'invalid amount' => [['invoiceType' => 'R1', 'rectificationType' => 'S', 'rectificationAmount' => new RectificationAmount('1', '1', 'x')], ['validation.rectification_amount.invalid']];
        yield 'invalid operation date' => [['operationDate' => '2026-06-30'], ['validation.operation_date.invalid']];
        yield 'recipients on an F2' => [['invoiceType' => 'F2', 'recipients' => [$customer]], ['validation.recipients.not_allowed']];
        yield 'recipient without name' => [['recipients' => [Recipient::withNif(' ', 'B12345674')]], ['validation.recipient.invalid']];
        yield 'recipient with an invalid NIF' => [['recipients' => [Recipient::withNif('X', 'B12345670')]], ['validation.recipient.invalid']];
        yield 'recipient with NIF and foreign ID' => [['recipients' => [new Recipient('X', 'B12345674', '02', 'DE1')]], ['validation.recipient.invalid']];
        yield 'foreign ID with unknown type' => [['recipients' => [Recipient::withForeignId('X', '09', 'A1', 'FR')]], ['validation.recipient.invalid']];
        yield 'foreign ID without ID' => [['recipients' => [Recipient::withForeignId('X', '03', ' ', 'FR')]], ['validation.recipient.invalid']];
        yield 'foreign ID without country (not a VAT number)' => [['recipients' => [Recipient::withForeignId('X', '03', 'P123')]], ['validation.recipient.invalid']];
        yield 'foreign VAT number without country is fine' => [['recipients' => [Recipient::withForeignId('X', '02', 'FR12345678901')]], []];
        yield 'bad country code' => [['recipients' => [Recipient::withForeignId('X', '03', 'P1', 'FRA')]], ['validation.recipient.invalid']];
        yield 'ES only with type 03 or 07' => [['recipients' => [Recipient::withForeignId('X', '04', 'P1', 'es')]], ['validation.recipient.invalid']];
        yield 'ES with type 03 is fine' => [['recipients' => [Recipient::withForeignId('X', '03', 'P1', 'ES')]], []];
        yield 'ES with type 07 is fine' => [['recipients' => [Recipient::withForeignId('X', '07', 'P1', 'ES')]], []];
        yield 'type 07 only with ES' => [['recipients' => [Recipient::withForeignId('X', '07', 'P1', 'FR')]], ['validation.recipient.invalid']];
        yield 'no regime key on Impuesto 05' => [['taxBreakdown' => [new TaxBreakdown('05', '21', '1', '0.21', '01')]], ['validation.tax_breakdown.regime_key.invalid']];
        yield 'IPSI only with its keys' => [['taxBreakdown' => [new TaxBreakdown('02', '10', '1', '0.10', '02')]], ['validation.tax_breakdown.regime_key.invalid']];
        yield 'IPSI with key 08 is fine' => [['taxBreakdown' => [new TaxBreakdown('02', '10', '1', '0.10', '08')]], []];
        yield 'E2 not under the general regime' => [['taxBreakdown' => [new TaxBreakdown('01', '', '1', '', '01', null, 'E2')]], ['validation.tax_breakdown.exemption_regime.invalid']];
        yield 'E2 under the export regime is fine' => [['taxBreakdown' => [new TaxBreakdown('', '', '1', '', '02', null, 'E2')]], []];
        yield 'qualification and exemption together' => [['taxBreakdown' => [new TaxBreakdown('01', '', '1', '', null, 'N1', 'E1')]], ['validation.tax_breakdown.qualification.invalid']];
        yield 'unknown qualification' => [['taxBreakdown' => [new TaxBreakdown('01', '21', '1', '0.21', null, 'S9')]], ['validation.tax_breakdown.qualification.invalid']];
        yield 'unknown exemption' => [['taxBreakdown' => [new TaxBreakdown('01', '', '1', '', null, null, 'E9')]], ['validation.tax_breakdown.qualification.invalid']];
        yield 'unknown regime key' => [['taxBreakdown' => [new TaxBreakdown('01', '21', '1', '0.21', '12')]], ['validation.tax_breakdown.regime_key.invalid']];
        yield 'S2 with a rate' => [['taxBreakdown' => [new TaxBreakdown('01', '21', '1', '0', null, 'S2')]], ['validation.tax_breakdown.reverse_charge.invalid']];
        yield 'S2 with a tax' => [['taxBreakdown' => [new TaxBreakdown('01', '0', '1', '0.21', null, 'S2')]], ['validation.tax_breakdown.reverse_charge.invalid']];
        yield 'S2 on an F2' => [['invoiceType' => 'F2', 'taxBreakdown' => [new TaxBreakdown('01', '0', '1', '0.00', null, 'S2')]], ['validation.tax_breakdown.reverse_charge.invalid']];
        yield 'N2 with a tax' => [['taxBreakdown' => [new TaxBreakdown('01', '21', '1', '0.21', null, 'N2')]], ['validation.tax_breakdown.not_taxed.invalid']];
        yield 'exempt with a tax' => [['taxBreakdown' => [new TaxBreakdown('01', '', '1', '1', null, null, 'E1')]], ['validation.tax_breakdown.not_taxed.invalid']];
        yield 'N2 with a zero tax is fine' => [['taxBreakdown' => [new TaxBreakdown('01', '', '1', '0.00', null, 'N2')]], []];
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<string> $expected
     */
    #[DataProvider('cases')]
    public function testRules(array $fields, array $expected): void
    {
        self::assertSame($expected, $this->validator()->validate($this->record($fields)));
    }

    public function testStrictModeRequiresRecipientsAndTheRectificationType(): void
    {
        $strict = $this->validator(true);

        self::assertSame(['validation.recipients.missing'], $strict->validate($this->record([])));
        self::assertSame([], $strict->validate($this->record(['invoiceType' => 'F2'])));
        self::assertSame(['validation.rectification_type.missing', 'validation.recipients.missing'], $strict->validate($this->record(['invoiceType' => 'R1'])));
        self::assertSame(['validation.rectification_type.missing'], $strict->validate($this->record(['invoiceType' => 'R5'])));
        self::assertSame([], $this->validator()->validate($this->record(['invoiceType' => 'R1'])), 'Not strict: the 1.5 behaviour');
    }

    public function testAnulacionIgnoresAltaFields(): void
    {
        self::assertSame([], $this->validator(true)->validate($this->record(['recordType' => RecordType::Anulacion, 'rectificationType' => 'X'])));
    }

    private function validator(bool $strict = false): AeatBusinessRulesValidator
    {
        return new AeatBusinessRulesValidator(new SpanishTaxIdValidator(), new IdentityTranslator(), $strict);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function record(array $fields): BillingRecord
    {
        return new BillingRecord(
            $fields['recordType'] ?? RecordType::Alta,
            '89890001K',
            'FAC-100',
            '09-07-2026',
            $fields['invoiceType'] ?? 'F1',
            '21.00',
            '121.00',
            '2026-07-09T16:00:00+02:00',
            taxBreakdown: $fields['taxBreakdown'] ?? [],
            recipients: $fields['recipients'] ?? [],
            rectificationType: $fields['rectificationType'] ?? null,
            rectifiedInvoices: $fields['rectifiedInvoices'] ?? [],
            substitutedInvoices: $fields['substitutedInvoices'] ?? [],
            rectificationAmount: $fields['rectificationAmount'] ?? null,
            operationDate: $fields['operationDate'] ?? null,
        );
    }
}
