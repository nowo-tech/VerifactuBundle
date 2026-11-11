<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Model;

/**
 * The AEAT answer to one submission (RespuestaRegFactuSistemaFacturacion): EstadoEnvio, CSV, TiempoEsperaEnvio and the
 * per-record lines; or the failure that prevented an answer (transport error, HTTP status, SoapFault, unreadable
 * body), in which case no record was registered by this request and the whole batch may be sent again.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class AeatBatchResponse
{
    public const ENVIO_CORRECT           = 'Correcto';
    public const ENVIO_PARTIALLY_CORRECT = 'ParcialmenteCorrecto';
    public const ENVIO_INCORRECT         = 'Incorrecto';

    /** SOAP 1.1 faultcode (local name) of a message the AEAT refused because of what it carries. */
    public const FAULT_CLIENT = 'Client';

    /** SOAP 1.1 faultcode (local name) of an AEAT-side error: "Reenviar mensaje" (Servicios web v1.0.3 §5.1). */
    public const FAULT_SERVER = 'Server';

    /**
     * @param list<AeatRecordResponse> $lines
     * @param int|null $waitSeconds TiempoEsperaEnvio returned by the AEAT (null when there was no valid answer)
     * @param bool $transportFailure no HTTP answer at all (connection, TLS, timeout): the request may or may not have
     *                               reached the AEAT
     * @param string|null $faultCode local name of the SOAP faultcode (Client / Server), for a SoapFault
     * @param int|null $faultErrorCode the AEAT Codigo[NNNN] of the faultstring, for a SoapFault
     */
    public function __construct(
        public readonly ?string $submissionStatus,
        public readonly array $lines = [],
        public readonly ?string $csv = null,
        public readonly ?int $waitSeconds = null,
        public readonly ?string $failure = null,
        public readonly int $httpStatus = 0,
        public readonly string $rawResponse = '',
        public readonly string $endpoint = '',
        public readonly bool $transportFailure = false,
        public readonly ?string $faultCode = null,
        public readonly ?int $faultErrorCode = null,
    ) {
    }

    /**
     * A response that carries no per-record outcome (transport error, HTTP error, SoapFault, unreadable body).
     */
    public static function failed(string $failure, int $httpStatus = 0, string $rawResponse = '', string $endpoint = '', bool $transportFailure = false, ?string $faultCode = null, ?int $faultErrorCode = null): self
    {
        return new self(null, [], null, null, $failure, $httpStatus, $rawResponse, $endpoint, $transportFailure, $faultCode, $faultErrorCode);
    }

    /**
     * Whether no per-record outcome is available: the records were not registered by this request.
     */
    public function isFailure(): bool
    {
        return $this->failure !== null;
    }

    /**
     * Whether the AEAT refused the message because of what it carries: a SOAP Fault with faultcode `Client`
     * (Servicios web v1.0.3 §5.1: the message is malformed or carries wrong data). Only such a refusal is counted
     * against the records of the batch (the batch is split, see BillingRecordBatchSubmitter); a Client fault about the
     * header (an issuer-wide error such as Codigo[4104]) is caught by the submitter's circuit breaker.
     *
     * Everything else is never counted, the records stay pending: a transport failure; a `Server` fault ("Reenviar
     * mensaje"); a non-SOAP, HTML or empty body; any HTTP status without a Client fault (401, 403, 404, 405, 407, 408,
     * 429, 500 outage pages, 502 – 504); an unreadable or invalid answer.
     */
    public function isRecordRefusal(): bool
    {
        return $this->failure !== null && !$this->transportFailure && $this->faultCode === self::FAULT_CLIENT;
    }

    /**
     * The line that answers the record, or null when the AEAT returned none for it.
     */
    public function lineFor(BillingRecord $record): ?AeatRecordResponse
    {
        foreach ($this->lines as $line) {
            if ($line->answers($record)) {
                return $line;
            }
        }

        return null;
    }
}
