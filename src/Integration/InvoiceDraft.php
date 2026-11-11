<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Integration;

use Nowo\VerifactuBundle\Model\Recipient;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Model\RectificationAmount;
use Nowo\VerifactuBundle\Model\RectifiedInvoice;
use Nowo\VerifactuBundle\Model\TaxBreakdown;

/**
 * Portable invoice draft for mapping into AEAT billing records.
 *
 * Use this DTO in ERP/billing modules before calling {@see InvoiceToBillingRecordMapper}.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class InvoiceDraft
{
    /**
     * Creates an invoice draft with the data needed to build a billing record.
     *
     * @param list<\Nowo\VerifactuBundle\Model\InvoiceLine> $lines
     * @param list<TaxBreakdown> $taxBreakdown
     * @param list<Recipient> $recipients
     * @param list<RectifiedInvoice> $rectifiedInvoices
     * @param list<RectifiedInvoice> $substitutedInvoices
     */
    public function __construct(
        public readonly string $issuerNif,
        public readonly string $invoiceSeriesNumber,
        public readonly string $issueDate,
        public readonly string $totalTaxAmount,
        public readonly string $totalAmount,
        public readonly string $generatedAt,
        public readonly RecordType $recordType = RecordType::Alta,
        public readonly string $invoiceType = 'F1',
        public readonly ?string $issuerName = null,
        public readonly ?string $operationDescription = null,
        public readonly array $lines = [],
        public readonly array $taxBreakdown = [],
        public readonly array $recipients = [],
        public readonly ?string $rectificationType = null,
        public readonly array $rectifiedInvoices = [],
        public readonly array $substitutedInvoices = [],
        public readonly ?RectificationAmount $rectificationAmount = null,
        public readonly ?string $operationDate = null,
    ) {
    }
}
