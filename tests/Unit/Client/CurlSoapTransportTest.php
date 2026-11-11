<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Client;

use Nowo\VerifactuBundle\Certificate\Certificate;
use Nowo\VerifactuBundle\Client\CurlSoapTransport;
use PHPUnit\Framework\TestCase;

use function dirname;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class CurlSoapTransportTest extends TestCase
{
    private CurlSoapTransport $transport;

    private false|string $guard = false;

    protected function setUp(): void
    {
        $this->transport = new CurlSoapTransport();
        // These tests reach only reserved, unroutable hosts (RFC 2606 / RFC 5737) to cover the cURL error paths, so
        // they lift the network guard for themselves and restore it afterwards.
        $this->guard = getenv(CurlSoapTransport::FORBID_NETWORK_ENV);
        self::setGuard('0');
    }

    protected function tearDown(): void
    {
        // @phpstan-ignore frankenphp.classic.noPutenv (test: restores the process-wide network guard)
        putenv($this->guard === false ? CurlSoapTransport::FORBID_NETWORK_ENV : CurlSoapTransport::FORBID_NETWORK_ENV . '=' . $this->guard);
    }

    public function testTheTestSuiteForbidsTheNetwork(): void
    {
        self::assertNotFalse($this->guard, 'phpunit.xml.dist must set VERIFACTU_FORBID_NETWORK');
        self::assertNotSame('0', $this->guard);
    }

    public function testTheGuardRefusesEveryRequestBeforeCurl(): void
    {
        self::assertFalse(CurlSoapTransport::isNetworkForbidden());
        self::setGuard('1');
        self::assertTrue(CurlSoapTransport::isNetworkForbidden());
        $certificate = dirname(__DIR__, 2) . '/Fixtures/certs/test.p12';

        // Reserved hosts only: even a broken guard must never make this test reach a real endpoint.
        foreach (['https://192.0.2.1/soap', 'https://invalid.localhost.test/soap'] as $endpoint) {
            $started = microtime(true);
            $result  = $this->transport->send($endpoint, 'submit', '<xml/>', $certificate, 'test', 5);
            self::assertSame(0, $result['status_code']);
            self::assertStringContainsString('VERIFACTU_FORBID_NETWORK', $result['error'] ?? '');
            self::assertLessThan(0.5, microtime(true) - $started, 'Refused without any network access');

            $memory = $this->transport->sendWithCertificate($endpoint, 'submit', '<xml/>', Certificate::fromContents('P12-BYTES'), 5);
            self::assertStringContainsString('VERIFACTU_FORBID_NETWORK', $memory['error'] ?? '');
        }

        self::setGuard('');
        self::assertFalse(CurlSoapTransport::isNetworkForbidden());
    }

    private static function setGuard(string $value): void
    {
        // @phpstan-ignore frankenphp.classic.noPutenv (test: lifts or sets the process-wide network guard)
        putenv(CurlSoapTransport::FORBID_NETWORK_ENV . '=' . $value);
    }

    public function testSendReturnsErrorWhenCertificatePathIsEmpty(): void
    {
        $result = $this->transport->send(
            'https://example.test/soap',
            'submit',
            '<xml/>',
            '',
            null,
            5,
        );

        self::assertSame(0, $result['status_code']);
        self::assertArrayHasKey('error', $result);
    }

    public function testSendRefusesPlainHttpEndpoints(): void
    {
        $result = $this->transport->send(
            'http://127.0.0.1:9/soap',
            'submit',
            '<xml/>',
            dirname(__DIR__, 2) . '/Fixtures/certs/test.p12',
            'test',
            2,
        );

        self::assertSame(0, $result['status_code']);
        self::assertStringContainsStringIgnoringCase('protocol', $result['error'] ?? '');
    }

    public function testSendReturnsErrorForInvalidEndpoint(): void
    {
        $certPath = dirname(__DIR__, 2) . '/Fixtures/certs/test.p12';

        $result = $this->transport->send(
            'https://invalid.localhost.test/soap',
            'submit',
            '<xml/>',
            $certPath,
            'test',
            2,
        );

        self::assertArrayHasKey('error', $result);
    }

    /**
     * REQ-RUNTIME-001: short CURLOPT_TIMEOUT must surface as transport error (not hang).
     */
    public function testSendFailsFastWhenTimeoutIsVeryShort(): void
    {
        $certPath = dirname(__DIR__, 2) . '/Fixtures/certs/test.p12';
        $started  = microtime(true);

        // Non-routable TEST-NET address — connect should hit CURLOPT_CONNECTTIMEOUT.
        $result = $this->transport->send(
            'https://192.0.2.1/soap',
            'submit',
            '<xml/>',
            $certPath,
            'test',
            1,
        );

        $elapsed = microtime(true) - $started;

        self::assertArrayHasKey('error', $result);
        self::assertLessThan(8.0, $elapsed, 'Transport must fail within a few seconds when timeout=1');
    }

    public function testSendUsesPemCertificateTypeForPemExtension(): void
    {
        $pemPath = sys_get_temp_dir() . '/verifactu-transport-' . uniqid('', true) . '.pem';
        file_put_contents($pemPath, 'dummy');

        try {
            $result = $this->transport->send(
                'https://invalid.localhost.test/soap',
                'submit',
                '<xml/>',
                $pemPath,
                null,
                2,
            );

            self::assertArrayHasKey('error', $result);
        } finally {
            @unlink($pemPath);
        }
    }

    public function testSendWithCertificateFromPathUsesTheFileOptions(): void
    {
        $result = $this->transport->sendWithCertificate('https://invalid.localhost.test/soap', 'action', '<xml/>', Certificate::fromPath('/nonexistent/cert.p12', 'pass'), 2);

        self::assertSame(0, $result['status_code']);
        self::assertNotEmpty($result['error'] ?? '');
    }

    public function testSendWithCertificateFromMemoryForPemAndPkcs12(): void
    {
        if (!$this->transport->supportsCertificateInMemory()) {
            self::markTestSkipped('This libcurl build has no CURLOPT_SSLCERT_BLOB.');
        }

        foreach ([Certificate::fromContents('PEM-BYTES', 'pass', 'pem'), Certificate::fromContents('P12-BYTES')] as $certificate) {
            $result = $this->transport->sendWithCertificate('https://invalid.localhost.test/soap', 'action', '<xml/>', $certificate, 2);

            self::assertSame(0, $result['status_code']);
            self::assertNotEmpty($result['error'] ?? '');
        }
    }
}
