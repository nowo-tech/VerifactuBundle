<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Model;

/**
 * Veri*Factu billing record (registro de facturación) data transfer object.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class BillingRecord
{
    /**
     * @param list<InvoiceLine> $lines
     * @param list<TaxBreakdown> $taxBreakdown
     */
    public function __construct(
        public readonly RecordType $recordType,
        public readonly string $issuerNif,
        public readonly string $invoiceSeriesNumber,
        public readonly string $issueDate,
        public readonly string $invoiceType,
        public readonly string $totalTaxAmount,
        public readonly string $totalAmount,
        public readonly string $generatedAt,
        public readonly array $lines = [],
        public readonly array $taxBreakdown = [],
        public ?string $previousHash = null,
        public ?string $hash = null,
        public ?string $xml = null,
        public ?string $signedXml = null,
        public ?string $issuerName = null,
        public ?string $operationDescription = null,
    ) {
    }

    public function withHash(string $hash, ?string $previousHash = null): self
    {
        return new self(
            $this->recordType,
            $this->issuerNif,
            $this->invoiceSeriesNumber,
            $this->issueDate,
            $this->invoiceType,
            $this->totalTaxAmount,
            $this->totalAmount,
            $this->generatedAt,
            $this->lines,
            $this->taxBreakdown,
            $previousHash ?? $this->previousHash,
            $hash,
            $this->xml,
            $this->signedXml,
            $this->issuerName,
            $this->operationDescription,
        );
    }

    public function withSignedXml(string $signedXml): self
    {
        return new self(
            $this->recordType,
            $this->issuerNif,
            $this->invoiceSeriesNumber,
            $this->issueDate,
            $this->invoiceType,
            $this->totalTaxAmount,
            $this->totalAmount,
            $this->generatedAt,
            $this->lines,
            $this->taxBreakdown,
            $this->previousHash,
            $this->hash,
            $this->xml,
            $signedXml,
            $this->issuerName,
            $this->operationDescription,
        );
    }

    public function withXml(string $xml): self
    {
        return new self(
            $this->recordType,
            $this->issuerNif,
            $this->invoiceSeriesNumber,
            $this->issueDate,
            $this->invoiceType,
            $this->totalTaxAmount,
            $this->totalAmount,
            $this->generatedAt,
            $this->lines,
            $this->taxBreakdown,
            $this->previousHash,
            $this->hash,
            $xml,
            $this->signedXml,
            $this->issuerName,
            $this->operationDescription,
        );
    }
}
