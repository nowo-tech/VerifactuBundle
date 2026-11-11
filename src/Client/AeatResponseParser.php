<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Client;

use DOMDocument;
use DOMDocumentType;
use DOMElement;
use Nowo\VerifactuBundle\Model\AeatBatchResponse;
use Nowo\VerifactuBundle\Model\AeatRecordResponse;
use Nowo\VerifactuBundle\Model\RecordSubmissionStatus;
use Nowo\VerifactuBundle\Model\RecordType;

use function in_array;
use function is_numeric;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function sprintf;
use function str_contains;
use function strlen;
use function trim;

use const LIBXML_NONET;

/**
 * Reads an AEAT submission response (RespuestaSuministro.xsd `RespuestaRegFactuSistemaFacturacion`, any namespace
 * prefix) into an AeatBatchResponse: EstadoEnvio, CSV, TiempoEsperaEnvio and one AeatRecordResponse per
 * RespuestaLinea. A SoapFault (AEAT Servicios web v1.0.3 p. 10: an XML format error rejects the whole message) or an
 * unreadable body gives a failed response. No DTD, no entity expansion, no network access.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class AeatResponseParser
{
    /** Responses are at most 1000 lines of a few hundred bytes; anything far larger is not an AEAT answer. */
    public const MAX_RESPONSE_BYTES = 16 * 1024 * 1024;

    /** EstadoEnvio values (RespuestaSuministro.xsd `EstadoEnvioType`). */
    private const SUBMISSION_STATES = [
        AeatBatchResponse::ENVIO_CORRECT,
        AeatBatchResponse::ENVIO_PARTIALLY_CORRECT,
        AeatBatchResponse::ENVIO_INCORRECT,
    ];

    /**
     * Parses the HTTP response of one submission.
     */
    public function parse(string $body, int $httpStatus = 200, string $endpoint = ''): AeatBatchResponse
    {
        if (trim($body) === '') {
            return AeatBatchResponse::failed($httpStatus >= 200 && $httpStatus < 300 ? 'Empty AEAT response body.' : sprintf('AEAT returned HTTP status %d.', $httpStatus), $httpStatus, $body, $endpoint);
        }
        if (strlen($body) > self::MAX_RESPONSE_BYTES || str_contains($body, '<!DOCTYPE') || str_contains($body, '<!ENTITY')) {
            return AeatBatchResponse::failed('The AEAT response is not a valid submission response.', $httpStatus, '', $endpoint);
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($body, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            return AeatBatchResponse::failed(sprintf('Unreadable AEAT response (HTTP status %d).', $httpStatus), $httpStatus, $body, $endpoint);
        }
        if ($document->doctype instanceof DOMDocumentType) {
            // The string check above misses other encodings (UTF-16): refuse any DTD once the document is parsed.
            return AeatBatchResponse::failed('The AEAT response is not a valid submission response.', $httpStatus, '', $endpoint);
        }

        $fault = $this->first($document, 'Fault');
        if ($fault instanceof DOMElement) {
            $message = $this->text($fault, 'faultstring') ?? 'AEAT SOAP fault';

            return AeatBatchResponse::failed('AEAT SOAP fault: ' . $message, $httpStatus, $body, $endpoint);
        }

        $answer = $this->first($document, 'RespuestaRegFactuSistemaFacturacion');
        $status = $answer instanceof DOMElement ? $this->text($answer, 'EstadoEnvio') : null;
        if (!$answer instanceof DOMElement || $status === null || !in_array($status, self::SUBMISSION_STATES, true)) {
            // Only the three EstadoEnvio values of RespuestaSuministro.xsd are an answer; an empty or unknown one is not.
            return AeatBatchResponse::failed(sprintf('The AEAT response has no valid EstadoEnvio (HTTP status %d).', $httpStatus), $httpStatus, $body, $endpoint);
        }
        if ($httpStatus < 200 || $httpStatus >= 300) {
            return AeatBatchResponse::failed(sprintf('AEAT returned HTTP status %d.', $httpStatus), $httpStatus, $body, $endpoint);
        }

        $lines = [];
        foreach ($answer->getElementsByTagNameNS('*', 'RespuestaLinea') as $line) {
            $parsed = $this->line($line);
            if ($parsed instanceof AeatRecordResponse) {
                $lines[] = $parsed;
            }
        }

        $wait = $this->text($answer, 'TiempoEsperaEnvio');
        $csv  = $this->text($answer, 'CSV');

        return new AeatBatchResponse(
            $status,
            $lines,
            $csv === null || $csv === '' ? null : $csv,
            $wait !== null && is_numeric($wait) ? (int) $wait : null,
            null,
            $httpStatus,
            $body,
            $endpoint,
        );
    }

    private function line(DOMElement $line): ?AeatRecordResponse
    {
        $operation = RecordType::tryFrom((string) $this->text($line, 'TipoOperacion'));
        $estado    = (string) $this->text($line, 'EstadoRegistro');
        $status    = RecordSubmissionStatus::fromAeat($estado);
        $issuer    = $this->text($line, 'IDEmisorFactura');
        $number    = $this->text($line, 'NumSerieFactura');
        $date      = $this->text($line, 'FechaExpedicionFactura');
        if (!$operation instanceof RecordType || !$status instanceof RecordSubmissionStatus || $issuer === null || $number === null || $date === null) {
            return null;
        }

        $duplicate       = $this->first($line, 'RegistroDuplicado');
        $duplicateStatus = $duplicate instanceof DOMElement ? $this->text($duplicate, 'EstadoRegistroDuplicado') : null;
        // A duplicate keeps the line's own EstadoRegistro (Incorrecto): only the submitter knows whether an earlier
        // send of the same record may have registered it (a retry) or the invoice id was used by another record.
        $code = $this->directText($line, 'CodigoErrorRegistro');

        return new AeatRecordResponse(
            $issuer,
            $number,
            $date,
            $operation,
            $status,
            $estado,
            $code !== null && is_numeric($code) ? (int) $code : null,
            $this->directText($line, 'DescripcionErrorRegistro'),
            $duplicate instanceof DOMElement ? $this->text($duplicate, 'IdPeticionRegistroDuplicado') : null,
            $duplicateStatus,
        );
    }

    private function first(DOMDocument|DOMElement $node, string $localName): ?DOMElement
    {
        $element = $node->getElementsByTagNameNS('*', $localName)->item(0);
        if (!$element instanceof DOMElement && $node instanceof DOMDocument) {
            // Unqualified SOAP fault children (faultstring) and responses without namespaces.
            $element = $node->getElementsByTagName($localName)->item(0);
        }

        return $element instanceof DOMElement ? $element : null;
    }

    private function text(DOMElement $node, string $localName): ?string
    {
        $element = $node->getElementsByTagNameNS('*', $localName)->item(0) ?? $node->getElementsByTagName($localName)->item(0);

        return $element instanceof DOMElement ? trim($element->textContent) : null;
    }

    /**
     * A child of the line itself (not the one inside RegistroDuplicado, which has the same name).
     */
    private function directText(DOMElement $line, string $localName): ?string
    {
        foreach ($line->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                return trim($child->textContent);
            }
        }

        return null;
    }
}
