<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Client;

use InvalidArgumentException;
use Nowo\VerifactuBundle\Client\AeatEndpointResolver;
use Nowo\VerifactuBundle\Client\NullAeatSubmissionClient;
use Nowo\VerifactuBundle\Client\SoapAeatSubmissionClient;
use Nowo\VerifactuBundle\Client\SoapEnvelopeBuilder;
use Nowo\VerifactuBundle\Client\SoapTransportInterface;
use Nowo\VerifactuBundle\Model\AeatBatchResponse;
use Nowo\VerifactuBundle\Model\AeatEnvironment;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordSubmissionStatus;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Tests\Support\FakeSoapTransport;
use PHPUnit\Framework\TestCase;

use function count;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class BatchSubmissionClientsTest extends TestCase
{
    public function testTheSoapClientSendsOneRequestAndParsesEveryLine(): void
    {
        $transport = new class implements SoapTransportInterface {
            /** @var list<array{string, string}> */
            public array $requests = [];

            public function send(string $endpoint, string $soapAction, string $payload, string $certificatePath, ?string $certificatePassword, int $timeout): array
            {
                $this->requests[] = [$endpoint, $payload];

                return ['status_code' => 200, 'body' => '<env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Body><R:RespuestaRegFactuSistemaFacturacion xmlns:R="urn:r" xmlns:S="urn:s">'
                    . '<R:CSV>CSV-9</R:CSV><R:TiempoEsperaEnvio>30</R:TiempoEsperaEnvio><R:EstadoEnvio>Correcto</R:EstadoEnvio>'
                    . '<R:RespuestaLinea><R:IDFactura><S:IDEmisorFactura>89890001K</S:IDEmisorFactura><S:NumSerieFactura>F-2</S:NumSerieFactura><S:FechaExpedicionFactura>09-07-2026</S:FechaExpedicionFactura></R:IDFactura>'
                    . '<R:Operacion><S:TipoOperacion>Alta</S:TipoOperacion></R:Operacion><R:EstadoRegistro>Correcto</R:EstadoRegistro></R:RespuestaLinea>'
                    . '</R:RespuestaRegFactuSistemaFacturacion></env:Body></env:Envelope>'];
            }
        };

        $response = $this->client($transport)->submitBatch([$this->record('F-1', AeatEnvironment::Production), $this->record('F-2', AeatEnvironment::Production)]);

        self::assertCount(1, $transport->requests);
        self::assertStringStartsWith('https://www1.agenciatributaria.gob.es/', $transport->requests[0][0]);
        self::assertSame(2, substr_count($transport->requests[0][1], '<sfLR:RegistroFactura>'));
        self::assertSame([AeatBatchResponse::ENVIO_CORRECT, 'CSV-9', 30, 1], [$response->submissionStatus, $response->csv, $response->waitSeconds, count($response->lines)]);
        self::assertSame(RecordSubmissionStatus::Accepted, $response->lineFor($this->record('F-2'))?->status);
    }

    public function testTransportErrorsAndSoapFaultsAreFailures(): void
    {
        self::assertSame('Could not resolve host', $this->client(new FakeSoapTransport('', 'Could not resolve host'))->submitBatch([$this->record('F-1')])->failure);
        self::assertStringStartsWith('AEAT SOAP fault', (string) $this->client(new FakeSoapTransport('<Envelope><Body><Fault><faultstring>bad</faultstring></Fault></Body></Envelope>', null, 500))->submitBatch([$this->record('F-1')])->failure);
    }

    public function testRecordsOfTwoEnvironmentsOrNoRecordsAreRefused(): void
    {
        $client = $this->client(new FakeSoapTransport());
        try {
            $client->submitBatch([$this->record('F-1', AeatEnvironment::Sandbox), $this->record('F-2', AeatEnvironment::Production)]);
            self::fail('Two environments were mixed.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        $client->submitBatch([]);
    }

    public function testTheNullClientAcceptsEveryRecord(): void
    {
        $response = (new NullAeatSubmissionClient())->submitBatch([$this->record('F-1'), $this->record('F-2')]);

        self::assertSame(AeatBatchResponse::ENVIO_CORRECT, $response->submissionStatus);
        self::assertSame(0, $response->waitSeconds);
        self::assertSame([RecordSubmissionStatus::Accepted, RecordSubmissionStatus::Accepted], array_map(static fn ($line) => $line->status, $response->lines));
    }

    private function client(SoapTransportInterface $transport): SoapAeatSubmissionClient
    {
        return new SoapAeatSubmissionClient(new SoapEnvelopeBuilder(), new AeatEndpointResolver(), $transport, ['nif' => '89890001K', 'name' => 'Test'], 'verifactu', 'sandbox', '/run/test.p12', null);
    }

    private function record(string $number, ?AeatEnvironment $environment = null): BillingRecord
    {
        return new BillingRecord(RecordType::Alta, '89890001K', $number, '09-07-2026', 'F1', '21.00', '121.00', '2026-07-09T16:00:00+02:00', hash: str_repeat('A', 64), xml: '<RegistroAlta/>', environment: $environment);
    }
}
