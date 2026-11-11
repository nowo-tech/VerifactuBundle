<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Validator;

use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\Recipient;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Model\RectificationAmount;
use Nowo\VerifactuBundle\Model\TaxBreakdown;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Translation\TranslatorInterface;

use function in_array;

/**
 * Validates AEAT business rules for billing records before hash/XML generation.
 *
 * The rules on the 1.6 fields (recipients, rectification, operation date, ClaveRegimen, CalificacionOperacion /
 * OperacionExenta) follow the AEAT document "Validaciones y errores" v1.2.2, §3.1.3 (items 3 – 7, 13, 15) and the
 * SuministroInformacion.xsd enumerations. They only apply to fields the record sets, so a 1.5 record validates as
 * before. With `$strict` (nowo_verifactu.validation.strict) the validator also rejects what the AEAT rejects because a
 * field earlier versions could not send is missing: recipients on F1, F3, R1 – R4 and TipoRectificativa on R1 – R5.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsAlias(id: self::SERVICE_NAME, public: true)]
final class AeatBusinessRulesValidator
{
    public const SERVICE_NAME = 'nowo_verifactu.validator.aeat_business_rules';

    /**
     * Receives the Spanish tax id validator and the translator.
     */
    /** ClaveRegimen values of SuministroInformacion.xsd (IdOperacionesTrascendenciaTributariaType). */
    public const REGIME_KEYS = ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '14', '15', '17', '18', '19', '20', '21'];

    /** ClaveRegimen values allowed with Impuesto 02 (IPSI, Ceuta and Melilla), validations v1.2.2 §15.6. */
    public const IPSI_REGIME_KEYS = ['01', '08', '11', '18', '19', '20'];

    private const RECTIFYING_TYPES   = ['R1', 'R2', 'R3', 'R4', 'R5'];
    private const WITHOUT_RECIPIENTS = ['F2', 'R5'];
    private const QUALIFICATIONS     = ['S1', 'S2', 'N1', 'N2'];
    private const EXEMPTIONS         = ['E1', 'E2', 'E3', 'E4', 'E5', 'E6', 'E7', 'E8'];
    private const FOREIGN_ID_TYPES   = ['02', '03', '04', '05', '06', '07'];

    /**
     * Receives the Spanish tax id validator, the translator and the strict flag.
     *
     * @param bool $strict also require the recipients (F1, F3, R1 – R4) and TipoRectificativa (R1 – R5) the AEAT requires
     */
    public function __construct(
        private readonly SpanishTaxIdValidator $taxIdValidator,
        private readonly TranslatorInterface $translator,
        private readonly bool $strict = false,
        private readonly ?string $defaultRegimeKey = null,
    ) {
    }

    /**
     * Checks the record against the AEAT business rules.
     *
     * @return list<string> Validation error messages (empty when valid)
     */
    public function validate(BillingRecord $record): array
    {
        $errors = [];

        if (!$this->taxIdValidator->isValid($record->issuerNif)) {
            $errors[] = $this->translator->trans('validation.issuer_nif.invalid', [], 'NowoVerifactuBundle');
        }

        if (trim($record->invoiceSeriesNumber) === '') {
            $errors[] = $this->translator->trans('validation.invoice_series_number.empty', [], 'NowoVerifactuBundle');
        }

        if (!$this->isValidDate($record->issueDate)) {
            $errors[] = $this->translator->trans('validation.issue_date.invalid', [], 'NowoVerifactuBundle');
        }

        if ($record->recordType === RecordType::Alta && trim($record->invoiceType) === '') {
            $errors[] = $this->translator->trans('validation.invoice_type.empty', [], 'NowoVerifactuBundle');
        }

        if ($record->recordType === RecordType::Alta && !$this->isValidAmount($record->totalAmount)) {
            $errors[] = $this->translator->trans('validation.total_amount.invalid', [], 'NowoVerifactuBundle');
        }

        if ($record->recordType === RecordType::Alta && !$this->isValidAmount($record->totalTaxAmount)) {
            $errors[] = $this->translator->trans('validation.total_tax_amount.invalid', [], 'NowoVerifactuBundle');
        }

        if (trim($record->generatedAt) === '') {
            $errors[] = $this->translator->trans('validation.generated_at.empty', [], 'NowoVerifactuBundle');
        }

        if ($record->recordType === RecordType::Alta) {
            foreach ($this->altaFieldErrors($record) as $key) {
                $errors[] = $this->translator->trans($key, [], 'NowoVerifactuBundle');
            }
        }

        if (!$this->hasValidCorrectionFlags($record)) {
            $errors[] = $this->translator->trans('validation.correction_flags.invalid', [], 'NowoVerifactuBundle');
        }

        return $errors;
    }

    /**
     * Subsanacion / RechazoPrevio / SinRegistroPrevio (AEAT Servicios web v1.0.3, Anexo II §9.1 – §10.2): Alta takes
     * Subsanacion S / N and RechazoPrevio N / S / X, where S and X (X: an Alta of a record the AEAT rejected) need
     * Subsanacion S (AEAT Validaciones v1.2.2 §3.1.3 item 2); Anulacion takes SinRegistroPrevio S / N and RechazoPrevio S / N.
     */
    private function hasValidCorrectionFlags(BillingRecord $record): bool
    {
        $flag  = static fn (?string $value): string => strtoupper(trim((string) $value));
        $yesNo = ['', BillingRecord::FLAG_YES, BillingRecord::FLAG_NO];

        if ($record->recordType === RecordType::Alta) {
            return in_array($flag($record->correction), $yesNo, true)
                && in_array($flag($record->previousRejection), [...$yesNo, BillingRecord::PREVIOUS_REJECTION_NOT_REGISTERED], true)
                && $flag($record->withoutPreviousRecord) === ''
                // RechazoPrevio S or X needs Subsanacion S (Validaciones v1.2.2 §3.1.3 item 2; Servicios web §9.1.3).
                && (in_array($flag($record->previousRejection), ['', BillingRecord::FLAG_NO], true) || $flag($record->correction) === BillingRecord::FLAG_YES);
        }

        return $flag($record->correction) === ''
            && in_array($flag($record->previousRejection), $yesNo, true)
            && in_array($flag($record->withoutPreviousRecord), $yesNo, true);
    }

    /**
     * The error returned when a record's issuer NIF differs from the host's issuer provider.
     *
     * @return list<string>
     */
    public function issuerMismatchErrors(): array
    {
        return [$this->translator->trans('validation.issuer_nif.provider_mismatch', [], 'NowoVerifactuBundle')];
    }

    /**
     * Rules on recipients, rectification, operation date and tax lines (validations v1.2.2, §3.1.3).
     *
     * @return list<string> translation keys
     */
    private function altaFieldErrors(BillingRecord $record): array
    {
        $type       = strtoupper(trim($record->invoiceType));
        $rectifying = in_array($type, self::RECTIFYING_TYPES, true);
        $keys       = [];

        // §3.1.3 item 3: TipoRectificativa only, and (strict) always, on R1 – R5.
        $rectificationType = $record->rectificationType === null ? '' : strtoupper(trim($record->rectificationType));
        if ($rectificationType !== '' && (!$rectifying || !in_array($rectificationType, [BillingRecord::RECTIFICATION_BY_SUBSTITUTION, BillingRecord::RECTIFICATION_BY_DIFFERENCES], true))) {
            $keys[] = 'validation.rectification_type.invalid';
        }
        if ($this->strict && $rectifying && $rectificationType === '') {
            $keys[] = 'validation.rectification_type.missing';
        }

        // Items 4 and 5: FacturasRectificadas on R1 – R5, FacturasSustituidas on F3.
        if ($record->rectifiedInvoices !== [] && !$rectifying) {
            $keys[] = 'validation.rectified_invoices.not_allowed';
        }
        if ($record->substitutedInvoices !== [] && $type !== 'F3') {
            $keys[] = 'validation.substituted_invoices.not_allowed';
        }
        foreach ([...$record->rectifiedInvoices, ...$record->substitutedInvoices] as $invoice) {
            if (!$this->taxIdValidator->isValid($invoice->issuerNif) || trim($invoice->invoiceSeriesNumber) === '' || !$this->isValidDate($invoice->issueDate)) {
                $keys[] = 'validation.invoice_reference.invalid';

                break;
            }
        }

        // Item 6: ImporteRectificacion with, and only with, TipoRectificativa S.
        $amount = $record->rectificationAmount;
        if (($rectificationType === BillingRecord::RECTIFICATION_BY_SUBSTITUTION) !== ($amount instanceof RectificationAmount)
            || ($amount instanceof RectificationAmount && (!$this->isValidAmount($amount->base) || !$this->isValidAmount($amount->tax) || ($amount->surcharge !== null && !$this->isValidAmount($amount->surcharge))))
        ) {
            $keys[] = 'validation.rectification_amount.invalid';
        }

        // Item 7: FechaOperacion is a real date.
        if ($record->operationDate !== null && !$this->isValidDate($record->operationDate)) {
            $keys[] = 'validation.operation_date.invalid';
        }

        // Item 13: Destinatarios required on F1, F3, R1 – R4 (strict), not allowed on F2 and R5.
        if ($record->recipients !== [] && in_array($type, self::WITHOUT_RECIPIENTS, true)) {
            $keys[] = 'validation.recipients.not_allowed';
        }
        if ($this->strict && $record->recipients === [] && !in_array($type, self::WITHOUT_RECIPIENTS, true)) {
            $keys[] = 'validation.recipients.missing';
        }
        foreach ($record->recipients as $recipient) {
            if (!$this->isValidRecipient($recipient)) {
                $keys[] = 'validation.recipient.invalid';

                break;
            }
        }

        foreach ($record->taxBreakdown as $breakdown) {
            $keys = [...$keys, ...$this->breakdownErrors($breakdown, $type)];
        }

        return array_values(array_unique($keys));
    }

    private function isValidRecipient(Recipient $recipient): bool
    {
        if (trim($recipient->name) === '') {
            return false;
        }
        if ($recipient->nif !== null) {
            return $recipient->foreignId === null && $this->taxIdValidator->isValid($recipient->nif);
        }

        $country = strtoupper(trim($recipient->countryCode ?? ''));

        return in_array($recipient->foreignIdType, self::FOREIGN_ID_TYPES, true)
            && trim((string) $recipient->foreignId) !== ''
            && ($country === '' ? $recipient->foreignIdType === Recipient::ID_TYPE_VAT_NUMBER : preg_match('/^[A-Z]{2}$/D', $country) === 1)
            // §3.1.3 item 13: with CodigoPais ES only IDType 03 (passport) or 07 (not registered); IDType 07 only with ES.
            && ($country !== 'ES' || in_array($recipient->foreignIdType, [Recipient::ID_TYPE_PASSPORT, Recipient::ID_TYPE_NOT_REGISTERED], true))
            && ($recipient->foreignIdType !== Recipient::ID_TYPE_NOT_REGISTERED || $country === 'ES');
    }

    /**
     * §15.4 – §15.6: one of CalificacionOperacion / OperacionExenta, a known ClaveRegimen, S2 with rate and tax 0 on
     * F1 / F3 / R1 – R4, no tax on N1, N2 and exempt lines.
     *
     * @return list<string> translation keys
     */
    private function breakdownErrors(TaxBreakdown $breakdown, string $invoiceType): array
    {
        $keys          = [];
        $qualification = strtoupper(trim($breakdown->qualification ?? ''));
        $exemption     = strtoupper(trim($breakdown->exemption ?? ''));

        if (($qualification !== '' && $exemption !== '')
            || ($qualification !== '' && !in_array($qualification, self::QUALIFICATIONS, true))
            || ($exemption !== '' && !in_array($exemption, self::EXEMPTIONS, true))
        ) {
            $keys[] = 'validation.tax_breakdown.qualification.invalid';
        }

        // §15.6: ClaveRegimen only for IVA (01 or no Impuesto), IPSI (02, keys 01 08 11 18 19 20) and IGIC (03).
        $taxType   = trim($breakdown->taxType);
        $regimeKey = $breakdown->regimeKey === null ? null : trim($breakdown->regimeKey);
        if ($regimeKey !== null && (!in_array($regimeKey, self::REGIME_KEYS, true)
            || !in_array($taxType, ['', '01', '02', '03'], true)
            || ($taxType === '02' && !in_array($regimeKey, self::IPSI_REGIME_KEYS, true)))
        ) {
            $keys[] = 'validation.tax_breakdown.regime_key.invalid';
        }

        // §15.5: under the general regime (ClaveRegimen 01) of IVA or IGIC, E2 / E3 (exports) are not allowed. The
        // effective key counts: the explicit one, or xml.default_regime_key where the generator applies it.
        $effectiveKey = $regimeKey ?? (in_array($taxType, ['', '01', '02', '03'], true) && $this->defaultRegimeKey !== null ? trim($this->defaultRegimeKey) : null);
        if ($effectiveKey === '01' && in_array($taxType, ['', '01', '03'], true) && in_array($exemption, ['E2', 'E3'], true)) {
            $keys[] = 'validation.tax_breakdown.exemption_regime.invalid';
        }

        if ($qualification === 'S2' && (!$this->isZero($breakdown->taxRate) || !$this->isZero($breakdown->taxAmount) || in_array($invoiceType, self::WITHOUT_RECIPIENTS, true))) {
            $keys[] = 'validation.tax_breakdown.reverse_charge.invalid';
        }

        if (($exemption !== '' || in_array($qualification, ['N1', 'N2'], true)) && trim($breakdown->taxAmount) !== '' && !$this->isZero($breakdown->taxAmount)) {
            $keys[] = 'validation.tax_breakdown.not_taxed.invalid';
        }

        return $keys;
    }

    private function isZero(string $amount): bool
    {
        return preg_match('/^[+-]?0*(\.0*)?$/D', trim($amount)) === 1 && trim($amount) !== '';
    }

    private function isValidDate(string $date): bool
    {
        $date = trim($date);
        if (!preg_match('/^(\d{2})-(\d{2})-(\d{4})$/D', $date, $parts)) {
            return false;
        }

        // A real calendar date (rejects 31-02-2026).
        return checkdate((int) $parts[2], (int) $parts[1], (int) $parts[3]);
    }

    private function isValidAmount(string $amount): bool
    {
        // AEAT ImporteSgn12.2: optional sign, up to 12 integer digits and up to 2 decimals (no exponent, no spaces).
        return (bool) preg_match('/^-?\d{1,12}(\.\d{1,2})?$/D', trim($amount));
    }
}
