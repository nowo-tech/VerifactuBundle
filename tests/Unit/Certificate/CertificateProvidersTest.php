<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Certificate;

use LogicException;
use Nowo\VerifactuBundle\Certificate\Certificate;
use Nowo\VerifactuBundle\Certificate\CertificateLoader;
use Nowo\VerifactuBundle\Certificate\ConfigCertificateProvider;
use Nowo\VerifactuBundle\Exception\InvalidAeatEnvironmentException;
use Nowo\VerifactuBundle\Model\AeatEnvironment;
use Nowo\VerifactuBundle\Provider\ConfigAeatEnvironmentProvider;
use PHPUnit\Framework\TestCase;

use function dirname;
use function strlen;

use const OPENSSL_KEYTYPE_RSA;

/**
 * V5 (1.6): certificate and AEAT environment resolved at runtime.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class CertificateProvidersTest extends TestCase
{
    public function testCertificateFromPathAndFromContents(): void
    {
        self::assertSame(Certificate::FORMAT_PKCS12, Certificate::fromPath('/run/a.PFX')->format);
        self::assertSame(Certificate::FORMAT_PEM, Certificate::fromPath('/run/a.pem')->format);
        self::assertSame(Certificate::FORMAT_PEM, Certificate::fromContents('x', null, 'pem')->format);
        self::assertSame(Certificate::FORMAT_PKCS12, Certificate::fromContents('x', null, 'anything')->format);

        $dump = print_r(Certificate::fromContents('raw-bytes', 'fixture-pass'), true);
        self::assertStringNotContainsString('raw-bytes', $dump);
        self::assertStringNotContainsString('fixture-pass', $dump);
        self::assertSame(['path' => '/a.pem', 'contents' => null, 'password' => null, 'format' => 'pem'], Certificate::fromPath('/a.pem')->__debugInfo());
    }

    public function testConfigProvidersReturnTheStaticConfiguration(): void
    {
        self::assertNull((new ConfigCertificateProvider())->getCertificate('89890001K'));
        self::assertNull((new ConfigCertificateProvider(''))->getCertificate('89890001K'));
        self::assertSame('/run/aeat.p12', (new ConfigCertificateProvider('/run/aeat.p12'))->getCertificate('B12345674')?->path);

        self::assertSame(AeatEnvironment::Sandbox, (new ConfigAeatEnvironmentProvider())->getEnvironment());
        self::assertSame(AeatEnvironment::Production, (new ConfigAeatEnvironmentProvider('production'))->getEnvironment());
    }

    public function testConfigEnvironmentProviderRefusesAnUnknownEnvironment(): void
    {
        $this->expectException(InvalidAeatEnvironmentException::class);
        (new ConfigAeatEnvironmentProvider('staging'))->getEnvironment();
    }

    public function testCertificateIsMaskedInJsonAndNeverSerialized(): void
    {
        $certificate = Certificate::fromContents('SECRET-BYTES', 'secret-pass', 'pem');

        $json = (string) json_encode($certificate);
        self::assertStringNotContainsString('SECRET-BYTES', $json);
        self::assertStringNotContainsString('secret-pass', $json);
        self::assertStringContainsString('"format":"pem"', $json);

        try {
            serialize($certificate);
            self::fail('A certificate was serialized.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        unserialize('O:' . strlen(Certificate::class) . ':"' . Certificate::class . '":0:{}');
    }

    public function testLoaderReadsCertificatesFromPathAndFromContents(): void
    {
        $loader = new CertificateLoader();
        $path   = dirname(__DIR__, 2) . '/Fixtures/certs/test.p12';

        $fromPath = $loader->loadCertificate(Certificate::fromPath($path, 'test'));
        self::assertSame($path, $fromPath['path']);

        $fromContents = $loader->loadCertificate(Certificate::fromContents((string) file_get_contents($path), 'test'));
        self::assertSame('', $fromContents['path']);
        self::assertSame($fromPath['certificate'], $fromContents['certificate']);

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'Verifactu PEM test'], $key);
        self::assertNotFalse($csr);
        self::assertNotTrue($csr);
        $x509 = openssl_csr_sign($csr, null, $key, 1);
        self::assertNotFalse($x509);
        openssl_pkey_export($key, $keyPem);
        openssl_x509_export($x509, $certPem);

        $pem = $loader->loadCertificate(Certificate::fromContents($certPem . $keyPem, null, Certificate::FORMAT_PEM));
        self::assertStringContainsString('BEGIN CERTIFICATE', $pem['certificate']);
        self::assertSame('', $pem['path']);
    }
}
