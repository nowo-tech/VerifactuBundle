<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Model;

/**
 * One RespuestaLinea of an AEAT submission response: the record it answers (IDFactura + Operacion), its
 * EstadoRegistro, the CodigoErrorRegistro / DescripcionErrorRegistro and, for a duplicate, the RegistroDuplicado data.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class AeatRecordResponse
{
    /**
     * @param string|null $estadoRegistro the raw EstadoRegistro (Correcto / AceptadoConErrores / Incorrecto)
     * @param string|null $duplicateStatus EstadoRegistroDuplicado of the record the AEAT already holds, for a duplicate
     */
    public function __construct(
        public readonly string $issuerNif,
        public readonly string $invoiceSeriesNumber,
        public readonly string $issueDate,
        public readonly RecordType $operation,
        public readonly RecordSubmissionStatus $status,
        public readonly ?string $estadoRegistro = null,
        public readonly ?int $errorCode = null,
        public readonly ?string $errorDescription = null,
        public readonly ?string $duplicateRequestId = null,
        public readonly ?string $duplicateStatus = null,
    ) {
    }

    /**
     * Whether the AEAT answered that the record already exists (RegistroDuplicado).
     */
    public function isDuplicate(): bool
    {
        return $this->duplicateStatus !== null;
    }

    /**
     * Whether this line answers the given record (issuer, series and number, issue date and operation).
     */
    public function answers(BillingRecord $record): bool
    {
        return self::key($record->issuerNif, $record->invoiceSeriesNumber, $record->issueDate, $record->recordType)
            === self::key($this->issuerNif, $this->invoiceSeriesNumber, $this->issueDate, $this->operation);
    }

    /**
     * The AEAT identity of a record inside one submission: IDEmisorFactura + NumSerieFactura + FechaExpedicionFactura
     * and the operation (Alta / Anulacion).
     */
    public static function key(string $issuerNif, string $invoiceSeriesNumber, string $issueDate, RecordType $operation): string
    {
        return strtoupper(trim($issuerNif)) . '|' . trim($invoiceSeriesNumber) . '|' . trim($issueDate) . '|' . $operation->value;
    }
}
