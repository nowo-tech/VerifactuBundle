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

    /**
     * @param list<AeatRecordResponse> $lines
     * @param int|null $waitSeconds TiempoEsperaEnvio returned by the AEAT (null when there was no valid answer)
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
    ) {
    }

    /**
     * A response that carries no per-record outcome (transport error, HTTP error, SoapFault, unreadable body).
     */
    public static function failed(string $failure, int $httpStatus = 0, string $rawResponse = '', string $endpoint = ''): self
    {
        return new self(null, [], null, null, $failure, $httpStatus, $rawResponse, $endpoint);
    }

    /**
     * Whether no per-record outcome is available: the records were not registered by this request.
     */
    public function isFailure(): bool
    {
        return $this->failure !== null;
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
