<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Client;

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

    protected function setUp(): void
    {
        $this->transport = new CurlSoapTransport();
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
}
