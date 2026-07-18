<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Integration;

use Nowo\VerifactuBundle\Model\RecordType;
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
     * @param list<\Nowo\VerifactuBundle\Model\InvoiceLine> $lines
     * @param list<TaxBreakdown> $taxBreakdown
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
    ) {
    }
}
