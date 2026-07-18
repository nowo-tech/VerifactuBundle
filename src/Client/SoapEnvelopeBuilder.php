<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Client;

use Nowo\VerifactuBundle\Model\BillingRecord;

use const ENT_QUOTES;
use const ENT_XML1;

/**
 * Builds AEAT SOAP envelopes for billing record submission.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class SoapEnvelopeBuilder
{
    private const NS_SOAP = 'http://schemas.xmlsoap.org/soap/envelope/';
    private const NS_LR   = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroLR.xsd';
    private const NS_SF   = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd';

    /**
     * @param array<string, mixed> $issuerConfig
     */
    public function buildSubmissionEnvelope(BillingRecord $record, array $issuerConfig): string
    {
        $recordXml   = $record->signedXml ?? $record->xml ?? '';
        $recordInner = $this->extractBodyInnerXml($recordXml);
        $issuerName  = (string) ($record->issuerName ?? $issuerConfig['name'] ?? '');

        $envelope = '<?xml version="1.0" encoding="UTF-8"?>';
        $envelope .= '<soapenv:Envelope xmlns:soapenv="' . self::NS_SOAP . '" xmlns:sfLR="' . self::NS_LR . '" xmlns:sf="' . self::NS_SF . '">';
        $envelope .= '<soapenv:Header/>';
        $envelope .= '<soapenv:Body>';
        $envelope .= '<sfLR:RegFactuSistemaFacturacion>';
        $envelope .= '<sfLR:Cabecera>';
        $envelope .= '<sf:ObligadoEmision>';
        $envelope .= '<sf:NombreRazon>' . htmlspecialchars($issuerName, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</sf:NombreRazon>';
        $envelope .= '<sf:NIF>' . htmlspecialchars($record->issuerNif, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</sf:NIF>';
        $envelope .= '</sf:ObligadoEmision>';
        $envelope .= '</sfLR:Cabecera>';
        $envelope .= '<sfLR:RegistroFactura>';
        $envelope .= $recordInner;
        $envelope .= '</sfLR:RegistroFactura>';
        $envelope .= '</sfLR:RegFactuSistemaFacturacion>';
        $envelope .= '</soapenv:Body>';
        $envelope .= '</soapenv:Envelope>';

        return $envelope;
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
