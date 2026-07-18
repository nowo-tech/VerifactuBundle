<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Integration;

use Nowo\VerifactuBundle\Model\BillingRecord;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Maps ERP invoice drafts into Veri*Factu {@see BillingRecord} DTOs.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsAlias(id: self::SERVICE_NAME, public: true)]
final class InvoiceToBillingRecordMapper
{
    public const SERVICE_NAME = 'nowo_verifactu.integration.invoice_to_billing_record_mapper';

    public function map(InvoiceDraft $invoice): BillingRecord
    {
        return new BillingRecord(
            $invoice->recordType,
            $invoice->issuerNif,
            $invoice->invoiceSeriesNumber,
            $invoice->issueDate,
            $invoice->invoiceType,
            $invoice->totalTaxAmount,
            $invoice->totalAmount,
            $invoice->generatedAt,
            $invoice->lines,
            $invoice->taxBreakdown,
            issuerName: $invoice->issuerName,
            operationDescription: $invoice->operationDescription,
        );
    }
}
