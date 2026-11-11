<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Model;

/**
 * Veri*Factu billing record (registro de facturación) data transfer object.
 *
 * The optional fields added in 1.6 (recipients, rectification, operation date) are emitted in the RegistroAlta only
 * when set, so a record built as in 1.5 produces the same XML. None of them is part of the fingerprint.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class BillingRecord
{
    public const RECTIFICATION_BY_SUBSTITUTION = 'S';
    public const RECTIFICATION_BY_DIFFERENCES  = 'I';

    /** Subsanacion / RechazoPrevio / SinRegistroPrevio flag values (AEAT SuministroInformacion.xsd). */
    public const FLAG_YES = 'S';
    public const FLAG_NO  = 'N';

    /** RechazoPrevio X: a new Alta of a record the AEAT rejected (with Subsanacion S). Servicios web v1.0.3 §9.1.3. */
    public const PREVIOUS_REJECTION_NOT_REGISTERED = 'X';

    /**
     * Creates a billing record (registro de facturación) value object.
     *
     * @param list<InvoiceLine> $lines
     * @param list<TaxBreakdown> $taxBreakdown
     * @param list<Recipient> $recipients Destinatarios (required by the AEAT for F1, F3, R1 – R4; not allowed for F2 / R5)
     * @param string|null $rectificationType TipoRectificativa: S (substitution) or I (differences); required for R1 – R5
     * @param list<RectifiedInvoice> $rectifiedInvoices FacturasRectificadas (R1 – R5 only)
     * @param list<RectifiedInvoice> $substitutedInvoices FacturasSustituidas (F3 only)
     * @param RectificationAmount|null $rectificationAmount ImporteRectificacion (required with, and only with, TipoRectificativa S)
     * @param string|null $operationDate FechaOperacion (dd-mm-yyyy) when it differs from the issue date
     * @param AeatEnvironment|null $environment the AEAT environment the record was chained for (set by the processor)
     * @param string|null $correction Subsanacion (S / N): a new record that corrects one already sent (1.7)
     * @param string|null $previousRejection RechazoPrevio: Alta N / S / X (X = corrects an Alta the AEAT rejected, with
     *                                       Subsanacion S); Anulacion S / N
     * @param string|null $withoutPreviousRecord SinRegistroPrevio (S / N), Anulacion only
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
        public readonly array $recipients = [],
        public readonly ?string $rectificationType = null,
        public readonly array $rectifiedInvoices = [],
        public readonly array $substitutedInvoices = [],
        public readonly ?RectificationAmount $rectificationAmount = null,
        public readonly ?string $operationDate = null,
        public readonly ?AeatEnvironment $environment = null,
        public readonly ?string $correction = null,
        public readonly ?string $previousRejection = null,
        public readonly ?string $withoutPreviousRecord = null,
    ) {
    }

    /**
     * Returns a copy with the record hash and the previous hash of the chain.
     */
    public function withHash(string $hash, ?string $previousHash = null): self
    {
        return $this->copy(previousHash: $previousHash ?? $this->previousHash, hash: $hash, xml: $this->xml, signedXml: $this->signedXml);
    }

    /**
     * Returns a copy carrying the signed XML.
     */
    public function withSignedXml(string $signedXml): self
    {
        return $this->copy(previousHash: $this->previousHash, hash: $this->hash, xml: $this->xml, signedXml: $signedXml);
    }

    /**
     * Returns a copy carrying the rendered XML.
     */
    public function withXml(string $xml): self
    {
        return $this->copy(previousHash: $this->previousHash, hash: $this->hash, xml: $xml, signedXml: $this->signedXml);
    }

    private function copy(?string $previousHash, ?string $hash, ?string $xml, ?string $signedXml): self
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
            $previousHash,
            $hash,
            $xml,
            $signedXml,
            $this->issuerName,
            $this->operationDescription,
            $this->recipients,
            $this->rectificationType,
            $this->rectifiedInvoices,
            $this->substitutedInvoices,
            $this->rectificationAmount,
            $this->operationDate,
            $this->environment,
            $this->correction,
            $this->previousRejection,
            $this->withoutPreviousRecord,
        );
    }
}
