<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Validator;

use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Validates AEAT business rules for billing records before hash/XML generation.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsAlias(id: self::SERVICE_NAME, public: true)]
final class AeatBusinessRulesValidator
{
    public const SERVICE_NAME = 'nowo_verifactu.validator.aeat_business_rules';

    public function __construct(
        private readonly SpanishTaxIdValidator $taxIdValidator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
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

        return $errors;
    }

    private function isValidDate(string $date): bool
    {
        $date = trim($date);

        return (bool) preg_match('/^\d{2}-\d{2}-\d{4}$/', $date);
    }

    private function isValidAmount(string $amount): bool
    {
        $amount = trim($amount);

        return $amount !== '' && is_numeric($amount);
    }
}
