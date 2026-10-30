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

            self::assertStringContainsString('-----BEGIN PRIVATE KEY-----', $material['private_key']);
            self::assertStringContainsString('-----BEGIN CERTIFICATE-----', $material['certificate']);
            self::assertStringNotContainsString('PRIVATE KEY', $material['certificate']);
            self::assertSame($pemPath, $material['path']);
            self::assertArrayNotHasKey('password', $material);
            self::assertNotFalse(openssl_pkey_get_private($material['private_key']));
        } finally {
            @unlink($pemPath);
        }
    }

    public function testPkcs12MaterialDoesNotCarryThePassword(): void
    {
        self::assertArrayNotHasKey('password', $this->loader->load($this->certificatePath, 'test'));
    }

    public function testLoadPemWithEncryptedPrivateKey(): void
    {
        $pemPath  = sys_get_temp_dir() . '/verifactu-encrypted-' . uniqid('', true) . '.pem';
        $password = bin2hex(random_bytes(8));
        $this->createPemCertificate($pemPath, $password);

        try {
            $material = $this->loader->load($pemPath, $password);
            self::assertStringContainsString('-----BEGIN PRIVATE KEY-----', $material['private_key']);

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Unable to load PEM private key');
            $this->loader->load($pemPath, 'wrong-' . $password);
        } finally {
            @unlink($pemPath);
        }
    }

    public function testLoadPemThrowsWithoutCertificate(): void
    {
        $pemPath = sys_get_temp_dir() . '/verifactu-keyonly-' . uniqid('', true) . '.pem';
        [$key]   = $this->generateKeyAndCertificate();
        file_put_contents($pemPath, $key);

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('contains no X.509 certificate');
            $this->loader->load($pemPath);
        } finally {
            @unlink($pemPath);
        }
    }

    public function testLoadPemThrowsWhenCertificateDoesNotMatchTheKey(): void
    {
        $pemPath              = sys_get_temp_dir() . '/verifactu-mismatch-' . uniqid('', true) . '.pem';
        [$key]                = $this->generateKeyAndCertificate();
        [, $otherCertificate] = $this->generateKeyAndCertificate();
        file_put_contents($pemPath, $key . "\n" . $otherCertificate);

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('does not match its private key');
            $this->loader->load($pemPath);
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

    private function createPemCertificate(string $path, ?string $password = null): void
    {
        [$key, $certificate] = $this->generateKeyAndCertificate($password);
        file_put_contents($path, $key . "\n" . $certificate);
    }

    /**
     * Runtime-generated throwaway RSA key and self-signed certificate (no key material in fixtures).
     *
     * @return array{0: string, 1: string} PEM private key (encrypted when a password is given), PEM certificate
     */
    private function generateKeyAndCertificate(?string $password = null): array
    {
        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($privateKey);

        $exportedKey = '';
        self::assertTrue(openssl_pkey_export($privateKey, $exportedKey, $password));

        $csr = openssl_csr_new(['commonName' => 'Verifactu Test'], $privateKey);
        self::assertNotFalse($csr);
        self::assertNotTrue($csr);

        $certificate = openssl_csr_sign($csr, null, $privateKey, 1);
        self::assertNotFalse($certificate);

        $exportedCert = '';
        self::assertTrue(openssl_x509_export($certificate, $exportedCert));

        return [$exportedKey, $exportedCert];
    }
}
