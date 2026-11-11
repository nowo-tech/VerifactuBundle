<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Client;

use InvalidArgumentException;
use Nowo\VerifactuBundle\Certificate\Certificate;
use Nowo\VerifactuBundle\Certificate\CertificateProviderInterface;
use Nowo\VerifactuBundle\Client\AeatEndpointResolver;
use Nowo\VerifactuBundle\Client\SoapAeatSubmissionClient;
use Nowo\VerifactuBundle\Client\SoapEnvelopeBuilder;
use Nowo\VerifactuBundle\Client\SoapTransportInterface;
use Nowo\VerifactuBundle\Model\AeatEnvironment;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\IssuerIdentity;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Provider\AeatEnvironmentProviderInterface;
use Nowo\VerifactuBundle\Provider\IssuerProviderInterface;
use Nowo\VerifactuBundle\Tests\Support\FakeSoapTransport;
use PHPUnit\Framework\TestCase;

use function dirname;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class SoapAeatSubmissionClientTest extends TestCase
{
    public function testSubmitThrowsWithoutCertificate(): void
    {
        $client = new SoapAeatSubmissionClient(
            new SoapEnvelopeBuilder(),
            new AeatEndpointResolver(),
            new FakeSoapTransport(),
            ['nif' => '89890001K'],
            'verifactu',
            'sandbox',
            null,
            null,
        );

        $this->expectException(InvalidArgumentException::class);
        $client->submit($this->createRecord());
    }

    public function testSubmitParsesSuccessfulResponse(): void
    {
        $client = new SoapAeatSubmissionClient(
            new SoapEnvelopeBuilder(),
            new AeatEndpointResolver(),
            new FakeSoapTransport('<CSV>ABC123</CSV><EstadoRegistro>Correcto</EstadoRegistro>'),
            ['nif' => '89890001K', 'name' => 'Demo'],
            'verifactu',
            'sandbox',
            dirname(__DIR__, 2) . '/Fixtures/certs/test.p12',
            'test',
        );

        $result = $client->submit($this->createRecord());

        self::assertTrue($result['success']);
        self::assertArrayHasKey('reference', $result);
        self::assertSame('ABC123', $result['reference']);
    }

    public function testSubmitParsesSoapFault(): void
    {
        $client = new SoapAeatSubmissionClient(
            new SoapEnvelopeBuilder(),
            new AeatEndpointResolver(),
            new FakeSoapTransport('<env:Fault><faultstring>Invalid certificate</faultstring></env:Fault>'),
            ['nif' => '89890001K', 'name' => 'Demo'],
            'verifactu',
            'sandbox',
            dirname(__DIR__, 2) . '/Fixtures/certs/test.p12',
            'test',
        );

        $result = $client->submit($this->createRecord());

        self::assertFalse($result['success']);
        self::assertArrayHasKey('errors', $result);
        self::assertStringContainsString('Invalid certificate', $result['errors'][0]);
    }

    public function testSubmitReturnsErrorForEmptyResponseBody(): void
    {
        $client = new SoapAeatSubmissionClient(
            new SoapEnvelopeBuilder(),
            new AeatEndpointResolver(),
            new FakeSoapTransport(''),
            ['nif' => '89890001K', 'name' => 'Demo'],
            'verifactu',
            'sandbox',
            dirname(__DIR__, 2) . '/Fixtures/certs/test.p12',
            'test',
        );

        $result = $client->submit($this->createRecord());

        self::assertFalse($result['success']);
        self::assertArrayHasKey('errors', $result);
        self::assertSame('Empty AEAT response body.', $result['errors'][0]);
    }

    public function testSubmitReturnsTransportError(): void
    {
        $client = new SoapAeatSubmissionClient(
            new SoapEnvelopeBuilder(),
            new AeatEndpointResolver(),
            new FakeSoapTransport('', 'Connection timed out'),
            ['nif' => '89890001K', 'name' => 'Demo'],
            'verifactu',
            'sandbox',
            dirname(__DIR__, 2) . '/Fixtures/certs/test.p12',
            'test',
        );

        $result = $client->submit($this->createRecord());

        self::assertFalse($result['success']);
        self::assertArrayHasKey('errors', $result);
        self::assertSame('Connection timed out', $result['errors'][0]);
    }

    public function testSubmitParsesIncorrectRegistrationStatus(): void
    {
        $client = new SoapAeatSubmissionClient(
            new SoapEnvelopeBuilder(),
            new AeatEndpointResolver(),
            new FakeSoapTransport('<EstadoRegistro>Incorrecto</EstadoRegistro>'),
            ['nif' => '89890001K', 'name' => 'Demo'],
            'verifactu',
            'sandbox',
            dirname(__DIR__, 2) . '/Fixtures/certs/test.p12',
            'test',
        );

        $result = $client->submit($this->createRecord());

        self::assertFalse($result['success']);
    }

    public function testSubmitTreatsMissingRegistrationStatusAsFailure(): void
    {
        $result = $this->createClient(new FakeSoapTransport('<CSV>ABC123</CSV>'))->submit($this->createRecord());

        self::assertFalse($result['success']);
        self::assertSame(['AEAT response does not contain an EstadoRegistro status.'], $result['errors'] ?? null);
        self::assertArrayNotHasKey('reference', $result);
    }

    public function testSubmitTreatsUnrelatedHtmlBodyAsFailure(): void
    {
        $result = $this->createClient(new FakeSoapTransport('<html><body>Service temporarily unavailable</body></html>'))->submit($this->createRecord());

        self::assertFalse($result['success']);
    }

    public function testSubmitTreatsNon2xxHttpStatusAsFailure(): void
    {
        $result = $this->createClient(new FakeSoapTransport('<CSV>ABC123</CSV><EstadoRegistro>Correcto</EstadoRegistro>', null, 503))->submit($this->createRecord());

        self::assertFalse($result['success']);
        self::assertSame(['AEAT returned HTTP status 503.'], $result['errors'] ?? null);
    }

    public function testSubmitParsesNamespacePrefixedAeatResponse(): void
    {
        $body = '<env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Body>'
            . '<tikR:RespuestaRegFactuSistemaFacturacion xmlns:tikR="urn:r" xmlns:tik="urn:t">'
            . '<tikR:CSV>A-XYZ987</tikR:CSV><tikR:EstadoEnvio>Correcto</tikR:EstadoEnvio>'
            . '<tikR:RespuestaLinea><tikR:EstadoRegistro>Correcto</tikR:EstadoRegistro></tikR:RespuestaLinea>'
            . '</tikR:RespuestaRegFactuSistemaFacturacion></env:Body></env:Envelope>';

        $result = $this->createClient(new FakeSoapTransport($body))->submit($this->createRecord());

        self::assertTrue($result['success']);
        self::assertSame('A-XYZ987', $result['reference'] ?? null);
    }

    public function testSubmitReportsAeatErrorDescriptionForRejectedRecord(): void
    {
        $body = '<tikR:EstadoRegistro>Incorrecto</tikR:EstadoRegistro>'
            . '<tikR:CodigoErrorRegistro>1100</tikR:CodigoErrorRegistro>'
            . '<tikR:DescripcionErrorRegistro>Valor o tipo incorrecto del campo: NIF</tikR:DescripcionErrorRegistro>';

        $result = $this->createClient(new FakeSoapTransport($body))->submit($this->createRecord());

        self::assertFalse($result['success']);
        self::assertSame(['AEAT registration status "Incorrecto": Valor o tipo incorrecto del campo: NIF'], $result['errors'] ?? null);
    }

    public function testSubmitTreatsAcceptedWithErrorsAsFailure(): void
    {
        $result = $this->createClient(new FakeSoapTransport('<EstadoRegistro>AceptadoConErrores</EstadoRegistro>'))->submit($this->createRecord());

        self::assertFalse($result['success']);
        self::assertSame(['AEAT registration status "AceptadoConErrores".'], $result['errors'] ?? null);
    }

    private function createClient(FakeSoapTransport $transport): SoapAeatSubmissionClient
    {
        return new SoapAeatSubmissionClient(
            new SoapEnvelopeBuilder(),
            new AeatEndpointResolver(),
            $transport,
            ['nif' => '89890001K', 'name' => 'Demo'],
            'verifactu',
            'sandbox',
            dirname(__DIR__, 2) . '/Fixtures/certs/test.p12',
            'test',
        );
    }

    public function testIssuerProviderIsAskedOnEverySubmission(): void
    {
        $transport = new class implements SoapTransportInterface {
            /** @var list<string> */
            public array $payloads = [];

            public function send(string $endpoint, string $soapAction, string $payload, string $certificatePath, ?string $certificatePassword, int $timeout): array
            {
                $this->payloads[] = $payload;

                return ['status_code' => 200, 'body' => '<EstadoRegistro>Correcto</EstadoRegistro>'];
            }
        };
        $provider = $this->createMock(IssuerProviderInterface::class);
        $provider->expects(self::exactly(2))->method('getIssuer')->willReturnOnConsecutiveCalls(
            new IssuerIdentity('89890001K', 'From Database One'),
            new IssuerIdentity('89890001K', 'From Database Two'),
        );
        $client = new SoapAeatSubmissionClient(
            new SoapEnvelopeBuilder(),
            new AeatEndpointResolver(),
            $transport,
            ['nif' => '89890001K', 'name' => 'Static'],
            'verifactu',
            'sandbox',
            dirname(__DIR__, 2) . '/Fixtures/certs/test.p12',
            'test',
            issuerProvider: $provider,
        );
        $record = new BillingRecord(RecordType::Alta, '89890001K', 'FAC-001', '09-07-2026', 'F1', '21.00', '121.00', '2026-07-09T16:00:00+02:00', hash: str_repeat('A', 64));

        $client->submit($record);
        $client->submit($record);

        self::assertStringContainsString('From Database One', $transport->payloads[0]);
        self::assertStringContainsString('From Database Two', $transport->payloads[1]);
    }

    public function testCertificateAndEnvironmentProvidersAreAskedPerSubmission(): void
    {
        $transport = new class implements SoapTransportInterface {
            /** @var list<array{endpoint: string, path: string, existed: bool, contents: string, passphrase: ?string}> */
            public array $calls = [];

            public function send(string $endpoint, string $soapAction, string $payload, string $certificatePath, ?string $certificatePassword, int $timeout): array
            {
                $this->calls[] = ['endpoint' => $endpoint, 'path' => $certificatePath, 'existed' => is_file($certificatePath), 'contents' => (string) @file_get_contents($certificatePath), 'passphrase' => $certificatePassword];

                return ['status_code' => 200, 'body' => '<EstadoRegistro>Correcto</EstadoRegistro>'];
            }
        };
        $certificates = $this->createMock(CertificateProviderInterface::class);
        $certificates->expects(self::exactly(2))->method('getCertificate')->with('89890001K')
            ->willReturn(Certificate::fromContents('P12-FROM-DATABASE', 'db-pass'));
        $environments = $this->createMock(AeatEnvironmentProviderInterface::class);
        $environments->method('getEnvironment')->willReturnOnConsecutiveCalls(AeatEnvironment::Sandbox, AeatEnvironment::Production);
        $client = new SoapAeatSubmissionClient(new SoapEnvelopeBuilder(), new AeatEndpointResolver(), $transport, ['nif' => '89890001K'], 'verifactu', 'sandbox', null, null, certificateProvider: $certificates, environmentProvider: $environments);

        self::assertTrue($client->submit($this->createRecord())['success']);
        self::assertTrue($client->submit($this->createRecord())['success']);

        self::assertStringStartsWith('https://prewww1.aeat.es/', $transport->calls[0]['endpoint']);
        self::assertStringStartsWith('https://www1.agenciatributaria.gob.es/', $transport->calls[1]['endpoint']);
        self::assertTrue($transport->calls[0]['existed']);
        self::assertSame('P12-FROM-DATABASE', $transport->calls[0]['contents']);
        self::assertSame('db-pass', $transport->calls[0]['passphrase']);
        self::assertFileDoesNotExist($transport->calls[0]['path'], 'The temporary certificate file is removed after the call');
    }

    public function testCertificateProviderWithoutCertificateForTheIssuerRefusesToSubmit(): void
    {
        $certificates = $this->createMock(CertificateProviderInterface::class);
        $certificates->method('getCertificate')->willReturn(null);
        $client = new SoapAeatSubmissionClient(new SoapEnvelopeBuilder(), new AeatEndpointResolver(), new FakeSoapTransport(), ['nif' => '89890001K'], 'verifactu', 'sandbox', '/ignored.p12', null, certificateProvider: $certificates);

        $this->expectException(InvalidArgumentException::class);
        $client->submit($this->createRecord());
    }

    private function createRecord(): BillingRecord
    {
        return new BillingRecord(
            RecordType::Alta,
            '89890001K',
            'FAC-001',
            '09-07-2026',
            'F1',
            '21.00',
            '121.00',
            '2026-07-09T16:00:00+02:00',
            hash: 'ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789',
            issuerName: 'Demo',
        );
    }
}
