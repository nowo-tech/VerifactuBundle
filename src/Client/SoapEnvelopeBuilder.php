<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Client;

use InvalidArgumentException;
use Nowo\VerifactuBundle\Model\BillingRecord;

use function count;
use function sprintf;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const ENT_XML1;

/**
 * Builds AEAT SOAP envelopes for billing record submission.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class SoapEnvelopeBuilder
{
    /** AEAT limit of records per submission (Servicios web v1.0.3 p. 10; SuministroLR.xsd maxOccurs="1000"). */
    public const MAX_RECORDS_PER_SUBMISSION = 1000;

    private const NS_SOAP = 'http://schemas.xmlsoap.org/soap/envelope/';
    private const NS_LR   = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroLR.xsd';
    private const NS_SF   = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd';

    /**
     * Builds the SOAP envelope (RegFactuSistemaFacturacion) that wraps the billing record XML.
     *
     * @param array<string, mixed> $issuerConfig
     */
    public function buildSubmissionEnvelope(BillingRecord $record, array $issuerConfig): string
    {
        return $this->buildBatchEnvelope([$record], $issuerConfig);
    }

    /**
     * Builds one RegFactuSistemaFacturacion with a Cabecera (the issuer of the records) and one RegistroFactura per
     * record, in the given (chain) order. All records must belong to the same issuer, and there may be at most
     * MAX_RECORDS_PER_SUBMISSION of them (AEAT Servicios web v1.0.3 p. 10: "El número máximo de registros por envío es
     * de 1.000"; SuministroLR.xsd `RegistroFactura maxOccurs="1000"`).
     *
     * @param list<BillingRecord> $records
     * @param array<string, mixed> $issuerConfig
     */
    public function buildBatchEnvelope(array $records, array $issuerConfig): string
    {
        if ($records === [] || count($records) > self::MAX_RECORDS_PER_SUBMISSION) {
            throw new InvalidArgumentException(sprintf('An AEAT submission carries 1 to %d records, %d given.', self::MAX_RECORDS_PER_SUBMISSION, count($records)));
        }
        $issuerNif = strtoupper(trim($records[0]->issuerNif));
        foreach ($records as $record) {
            if (strtoupper(trim($record->issuerNif)) !== $issuerNif) {
                throw new InvalidArgumentException('All records of one AEAT submission must belong to the same issuer (Cabecera ObligadoEmision).');
            }
        }
        $issuerName = (string) ($records[0]->issuerName ?? $issuerConfig['name'] ?? '');

        $envelope = '<?xml version="1.0" encoding="UTF-8"?>';
        $envelope .= '<soapenv:Envelope xmlns:soapenv="' . self::NS_SOAP . '" xmlns:sfLR="' . self::NS_LR . '" xmlns:sf="' . self::NS_SF . '">';
        $envelope .= '<soapenv:Header/>';
        $envelope .= '<soapenv:Body>';
        $envelope .= '<sfLR:RegFactuSistemaFacturacion>';
        $envelope .= '<sfLR:Cabecera>';
        $envelope .= '<sf:ObligadoEmision>';
        $envelope .= '<sf:NombreRazon>' . htmlspecialchars($issuerName, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</sf:NombreRazon>';
        $envelope .= '<sf:NIF>' . htmlspecialchars($records[0]->issuerNif, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</sf:NIF>';
        $envelope .= '</sf:ObligadoEmision>';
        $envelope .= '</sfLR:Cabecera>';
        foreach ($records as $record) {
            $envelope .= '<sfLR:RegistroFactura>';
            $envelope .= $this->extractBodyInnerXml($record->signedXml ?? $record->xml ?? '');
            $envelope .= '</sfLR:RegistroFactura>';
        }
        $envelope .= '</sfLR:RegFactuSistemaFacturacion>';
        $envelope .= '</soapenv:Body>';

        return $envelope . '</soapenv:Envelope>';
    }

    private function extractBodyInnerXml(string $recordXml): string
    {
        $trimmed = trim($recordXml);
        if ($trimmed === '') {
            return '';
        }

        if (str_starts_with($trimmed, '<?xml')) {
            $trimmed = preg_replace('/<\?xml[^?]*\?>\s*/', '', $trimmed) ?? $trimmed;
        }

        return trim($trimmed);
    }
}
