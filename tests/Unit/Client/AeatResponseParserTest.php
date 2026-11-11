<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Client;

use DOMDocument;
use Nowo\VerifactuBundle\Client\AeatResponseParser;
use Nowo\VerifactuBundle\Model\AeatBatchResponse;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordSubmissionStatus;
use Nowo\VerifactuBundle\Model\RecordType;
use PHPUnit\Framework\TestCase;

use function dirname;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class AeatResponseParserTest extends TestCase
{
    private const NS_R  = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/RespuestaSuministro.xsd';
    private const NS_SF = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd';

    public function testAPartiallyCorrectSubmissionWithEveryKindOfLine(): void
    {
        $body = $this->response('ParcialmenteCorrecto', '45', 'A-CSV-123', [
            $this->line('F-1', 'Alta', 'Correcto'),
            $this->line('F-2', 'Alta', 'AceptadoConErrores', '<tikR:CodigoErrorRegistro>2004</tikR:CodigoErrorRegistro><tikR:DescripcionErrorRegistro>Admisible</tikR:DescripcionErrorRegistro>'),
            $this->line('F-3', 'Anulacion', 'Incorrecto', '<tikR:CodigoErrorRegistro>1100</tikR:CodigoErrorRegistro><tikR:DescripcionErrorRegistro>Valor incorrecto</tikR:DescripcionErrorRegistro>'),
            $this->line('F-4', 'Alta', 'Incorrecto', '<tikR:CodigoErrorRegistro>3000</tikR:CodigoErrorRegistro><tikR:DescripcionErrorRegistro>Registro de facturación duplicado.</tikR:DescripcionErrorRegistro>'
                . '<tikR:RegistroDuplicado><tik:IdPeticionRegistroDuplicado>202611110001</tik:IdPeticionRegistroDuplicado><tik:EstadoRegistroDuplicado>Correcta</tik:EstadoRegistroDuplicado>'
                . '<tik:CodigoErrorRegistro>9999</tik:CodigoErrorRegistro></tikR:RegistroDuplicado>'),
        ]);
        self::assertTrue($this->isValidResponse($body), 'The fixture follows RespuestaSuministro.xsd');

        $response = (new AeatResponseParser())->parse($body, 200, 'https://prewww1.aeat.es/x');

        self::assertFalse($response->isFailure());
        self::assertSame(AeatBatchResponse::ENVIO_PARTIALLY_CORRECT, $response->submissionStatus);
        self::assertSame('A-CSV-123', $response->csv);
        self::assertSame(45, $response->waitSeconds);
        self::assertCount(4, $response->lines);
        [$accepted, $withErrors, $rejected, $duplicate] = $response->lines;
        self::assertSame(RecordSubmissionStatus::Accepted, $accepted->status);
        self::assertSame([RecordSubmissionStatus::AcceptedWithErrors, 2004, 'Admisible'], [$withErrors->status, $withErrors->errorCode, $withErrors->errorDescription]);
        self::assertSame([RecordSubmissionStatus::Rejected, RecordType::Anulacion, 1100], [$rejected->status, $rejected->operation, $rejected->errorCode]);
        self::assertTrue($duplicate->isDuplicate());
        self::assertSame(['Correcta', '202611110001', 3000, RecordSubmissionStatus::Rejected], [$duplicate->duplicateStatus, $duplicate->duplicateRequestId, $duplicate->errorCode, $duplicate->status], 'The line code, not the one inside RegistroDuplicado');

        $record = new BillingRecord(RecordType::Anulacion, ' 89890001k ', 'F-3', '09-07-2026', 'F1', '0', '0', '');
        self::assertSame($rejected, $response->lineFor($record));
        self::assertNull($response->lineFor(new BillingRecord(RecordType::Alta, '89890001K', 'F-3', '09-07-2026', 'F1', '0', '0', '')));
    }

    public function testFailures(): void
    {
        $parser = new AeatResponseParser();

        self::assertSame('Empty AEAT response body.', $parser->parse('')->failure);
        self::assertSame('AEAT returned HTTP status 503.', $parser->parse('', 503)->failure);
        self::assertSame('AEAT SOAP fault: Codigo[4102].El XML no cumple el esquema.', $parser->parse(
            '<env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Body><env:Fault><faultcode>env:Client</faultcode>'
            . '<faultstring>Codigo[4102].El XML no cumple el esquema.</faultstring></env:Fault></env:Body></env:Envelope>',
            500,
        )->failure);
        self::assertStringStartsWith('Unreadable AEAT response', (string) $parser->parse('<html>Bad gateway', 502)->failure);
        self::assertStringStartsWith('The AEAT response has no valid EstadoEnvio', (string) $parser->parse('<ok/>')->failure);
        self::assertSame('AEAT returned HTTP status 500.', $parser->parse($this->response('Correcto', '60', null, []), 500)->failure);
        self::assertSame('The AEAT response is not a valid submission response.', $parser->parse('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e "x">]><x>&e;</x>')->failure);
        self::assertTrue($parser->parse(str_repeat(' ', AeatResponseParser::MAX_RESPONSE_BYTES + 1) . '<x/>')->isFailure());
    }

    /**
     * LJ-L6: a DTD in another encoding gets past the string check; the parsed document is refused all the same.
     */
    public function testADoctypeInUtf16IsRefusedAfterParsing(): void
    {
        $utf8 = '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/hostname">]>'
            . '<RespuestaRegFactuSistemaFacturacion><EstadoEnvio>Correcto</EstadoEnvio><CSV>&e;</CSV></RespuestaRegFactuSistemaFacturacion>';
        $body = "\xFF\xFE" . (string) mb_convert_encoding($utf8, 'UTF-16LE', 'UTF-8');
        self::assertStringNotContainsString('<!DOCTYPE', $body, 'The probe must get past the string check');

        $response = (new AeatResponseParser())->parse($body);

        self::assertTrue($response->isFailure());
        self::assertSame('The AEAT response is not a valid submission response.', $response->failure);
        self::assertNull($response->csv);
    }

    /**
     * LJ-L6: only Correcto / ParcialmenteCorrecto / Incorrecto are an answer; an empty or unknown EstadoEnvio is a failure.
     */
    public function testAnEmptyOrUnknownSubmissionStateIsAFailure(): void
    {
        $parser = new AeatResponseParser();

        foreach (['', 'Desconocido', 'correcto'] as $state) {
            $response = $parser->parse($this->response($state, '60', 'CSV-1', []));
            self::assertTrue($response->isFailure(), $state);
            self::assertFalse($response->isRecordRefusal(), 'Not a SOAP Client fault: never counted against the records (LJD-M1)');
        }
        foreach ([AeatBatchResponse::ENVIO_CORRECT, AeatBatchResponse::ENVIO_PARTIALLY_CORRECT, AeatBatchResponse::ENVIO_INCORRECT] as $state) {
            self::assertFalse($parser->parse($this->response($state, '60', 'CSV-1', []))->isFailure(), $state);
        }
    }

    /**
     * LJD-M1 (Servicios web v1.0.3 §5.1): only a SOAP fault with faultcode Client counts against the records; a Server
     * fault means "resend", and HTML / empty bodies and HTTP errors are never about a record.
     */
    public function testOnlyAClientFaultIsARecordRefusal(): void
    {
        $parser = new AeatResponseParser();
        $fault  = static fn (string $code, string $text = 'Codigo[4102].El XML no cumple el esquema.'): string => '<env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Body><env:Fault><faultcode>' . $code . '</faultcode>'
            . '<faultstring>' . $text . '</faultstring></env:Fault></env:Body></env:Envelope>';

        $client = $parser->parse($fault('env:Client'), 500);
        self::assertTrue($client->isRecordRefusal());
        self::assertSame([AeatBatchResponse::FAULT_CLIENT, 4102], [$client->faultCode, $client->faultErrorCode]);
        self::assertTrue($parser->parse($fault('soapenv:Client.Validation'), 200)->isRecordRefusal());
        self::assertTrue($parser->parse($fault('Client', 'Codigo[4104].Cabecera'), 500)->isRecordRefusal(), 'A header Client fault counts; the circuit breaker catches it');

        $server = $parser->parse($fault('env:Server', 'Codigo[4118].Error técnico'), 500);
        self::assertSame([AeatBatchResponse::FAULT_SERVER, 4118], [$server->faultCode, $server->faultErrorCode]);
        self::assertFalse($server->isRecordRefusal());
        self::assertFalse($parser->parse($fault('env:Other', 'sin código'), 500)->isRecordRefusal());
        self::assertNull($parser->parse($fault('', 'sin código'), 500)->faultCode);
        self::assertNull($parser->parse($fault('env:Client', 'sin código'), 500)->faultErrorCode);

        foreach ([401, 403, 404, 405, 407, 408, 429, 500, 502, 503, 504] as $status) {
            self::assertFalse($parser->parse('<html><body>Error ' . $status . '</body></html>', $status)->isRecordRefusal(), (string) $status);
            self::assertFalse($parser->parse('', $status)->isRecordRefusal(), (string) $status);
        }
        self::assertFalse($parser->parse('<ok/>')->isRecordRefusal());
        self::assertFalse(AeatBatchResponse::failed('Operation timed out', 0, '', '', true)->isRecordRefusal());
        self::assertFalse(AeatBatchResponse::failed('reset', 200, '', '', true, AeatBatchResponse::FAULT_CLIENT)->isRecordRefusal(), 'A transport failure never counts');
        self::assertFalse($parser->parse($this->response('Correcto', '60', null, []))->isRecordRefusal());
    }

    public function testAnUnknownStatusLineIsIgnoredAndNoWaitTimeIsNull(): void
    {
        $body = str_replace('<tikR:TiempoEsperaEnvio>60</tikR:TiempoEsperaEnvio>', '<tikR:TiempoEsperaEnvio></tikR:TiempoEsperaEnvio>', $this->response('Correcto', '60', null, [$this->line('F-1', 'Alta', 'Desconocido')]));

        $response = (new AeatResponseParser())->parse($body);

        self::assertSame([], $response->lines);
        self::assertNull($response->waitSeconds);
        self::assertNull($response->csv);
    }

    /**
     * @param list<string> $lines
     */
    private function response(string $status, string $wait, ?string $csv, array $lines): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Header/><env:Body>'
            . '<tikR:RespuestaRegFactuSistemaFacturacion xmlns:tikR="' . self::NS_R . '" xmlns:tik="' . self::NS_SF . '">'
            . ($csv !== null ? '<tikR:CSV>' . $csv . '</tikR:CSV>' : '')
            . '<tikR:Cabecera><tik:ObligadoEmision><tik:NombreRazon>Test Issuer</tik:NombreRazon><tik:NIF>89890001K</tik:NIF></tik:ObligadoEmision></tikR:Cabecera>'
            . '<tikR:TiempoEsperaEnvio>' . $wait . '</tikR:TiempoEsperaEnvio>'
            . '<tikR:EstadoEnvio>' . $status . '</tikR:EstadoEnvio>'
            . implode('', $lines)
            . '</tikR:RespuestaRegFactuSistemaFacturacion></env:Body></env:Envelope>';
    }

    private function line(string $number, string $operation, string $status, string $extra = ''): string
    {
        return '<tikR:RespuestaLinea><tikR:IDFactura><tik:IDEmisorFactura>89890001K</tik:IDEmisorFactura><tik:NumSerieFactura>' . $number . '</tik:NumSerieFactura>'
            . '<tik:FechaExpedicionFactura>09-07-2026</tik:FechaExpedicionFactura></tikR:IDFactura>'
            . '<tikR:Operacion><tik:TipoOperacion>' . $operation . '</tik:TipoOperacion></tikR:Operacion>'
            . '<tikR:EstadoRegistro>' . $status . '</tikR:EstadoRegistro>' . $extra . '</tikR:RespuestaLinea>';
    }

    private function isValidResponse(string $envelope): bool
    {
        $soap = new DOMDocument();
        $soap->loadXML($envelope);
        $answer   = $soap->getElementsByTagNameNS(self::NS_R, 'RespuestaRegFactuSistemaFacturacion')->item(0);
        $document = new DOMDocument();
        self::assertNotNull($answer);
        $document->appendChild($document->importNode($answer, true));

        return $document->schemaValidate(dirname(__DIR__, 3) . '/src/Resources/schemas/aeat/RespuestaSuministro.xsd');
    }
}
