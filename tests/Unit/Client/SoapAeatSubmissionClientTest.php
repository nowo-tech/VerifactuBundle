<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Client;

use InvalidArgumentException;
use Nowo\VerifactuBundle\Client\AeatEndpointResolver;
use Nowo\VerifactuBundle\Client\SoapAeatSubmissionClient;
use Nowo\VerifactuBundle\Client\SoapEnvelopeBuilder;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
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
