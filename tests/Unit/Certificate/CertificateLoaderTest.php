<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Certificate;

use InvalidArgumentException;
use Nowo\VerifactuBundle\Certificate\CertificateLoader;
use PHPUnit\Framework\TestCase;

use function dirname;

use const OPENSSL_KEYTYPE_RSA;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class CertificateLoaderTest extends TestCase
{
    private CertificateLoader $loader;

    private string $certificatePath;

    protected function setUp(): void
    {
        $this->loader          = new CertificateLoader();
        $this->certificatePath = dirname(__DIR__, 2) . '/Fixtures/certs/test.p12';
    }

    public function testLoadPkcs12Certificate(): void
    {
        $material = $this->loader->load($this->certificatePath, 'test');

        self::assertNotSame('', $material['private_key']);
        self::assertNotSame('', $material['certificate']);
        self::assertSame($this->certificatePath, $material['path']);
    }

    public function testLoadThrowsWhenFileMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->loader->load('/non/existent/cert.p12');
    }

    public function testLoadThrowsWhenPkcs12PasswordIsWrong(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->loader->load($this->certificatePath, 'wrong-password');
    }

    public function testLoadPemCertificate(): void
    {
        $pemPath = sys_get_temp_dir() . '/verifactu-test-' . uniqid('', true) . '.pem';
        $this->createPemCertificate($pemPath);

        try {
            $material = $this->loader->load($pemPath);

            self::assertNotSame('', $material['private_key']);
            self::assertNotSame('', $material['certificate']);
            self::assertSame($pemPath, $material['path']);
        } finally {
            @unlink($pemPath);
        }
    }

    public function testLoadPemThrowsWhenPrivateKeyIsInvalid(): void
    {
        $pemPath = sys_get_temp_dir() . '/verifactu-invalid-' . uniqid('', true) . '.pem';
        file_put_contents($pemPath, 'not-a-private-key');

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->loader->load($pemPath);
        } finally {
            @unlink($pemPath);
        }
    }

    private function createPemCertificate(string $path): void
    {
        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($privateKey);

        $exportedKey = '';
        self::assertTrue(openssl_pkey_export($privateKey, $exportedKey));

        $csr = openssl_csr_new(['commonName' => 'Verifactu Test'], $privateKey);
        self::assertNotFalse($csr);
        self::assertNotTrue($csr);

        $certificate = openssl_csr_sign($csr, null, $privateKey, 1);
        self::assertNotFalse($certificate);

        $exportedCert = '';
        self::assertTrue(openssl_x509_export($certificate, $exportedCert));

        file_put_contents($path, $exportedKey . "\n" . $exportedCert);
    }
}
