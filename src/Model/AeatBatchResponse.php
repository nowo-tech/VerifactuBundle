<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Model;

use function in_array;

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

    /** HTTP statuses of a gateway or an unavailable service: they say nothing about the records of the request. */
    private const UNAVAILABLE_STATUSES = [502, 503, 504];

    /**
     * @param list<AeatRecordResponse> $lines
     * @param int|null $waitSeconds TiempoEsperaEnvio returned by the AEAT (null when there was no valid answer)
     * @param bool $transportFailure no HTTP answer at all (connection, TLS, timeout): the request may or may not have
     *                               reached the AEAT
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
    ) {
    }

    /**
     * A response that carries no per-record outcome (transport error, HTTP error, SoapFault, unreadable body).
     */
    public static function failed(string $failure, int $httpStatus = 0, string $rawResponse = '', string $endpoint = '', bool $transportFailure = false): self
    {
        return new self(null, [], null, null, $failure, $httpStatus, $rawResponse, $endpoint, $transportFailure);
    }

    /**
     * Whether no per-record outcome is available: the records were not registered by this request.
     */
    public function isFailure(): bool
    {
        return $this->failure !== null;
    }

    /**
     * Whether the AEAT answered the request without a per-record outcome in a way the request itself can cause: a
     * SoapFault (an XML format error rejects the whole message, Servicios web v1.0.3 p. 10), an HTTP 4xx / 500 or an
     * unreadable / invalid body. Such a failure is counted against the records of the batch (the batch is split, then
     * a record that keeps failing on its own is marked failed). A transport failure (no HTTP answer) or a gateway /
     * unavailable status (502, 503, 504) is not: nothing points at the records, they stay pending.
     */
    public function isAnsweredFailure(): bool
    {
        return $this->failure !== null
            && !$this->transportFailure
            && $this->httpStatus > 0
            && !in_array($this->httpStatus, self::UNAVAILABLE_STATUSES, true);
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
