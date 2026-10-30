<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Signer;

use DOMDocument;
use InvalidArgumentException;
use Nowo\VerifactuBundle\Certificate\CertificateLoader;
use Nowo\VerifactuBundle\Generator\BillingRecordXmlGenerator;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Signer\XadesBillingRecordSigner;
use PHPUnit\Framework\TestCase;
use RobRichards\XMLSecLibs\XMLSecEnc;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

use function dirname;

use const OPENSSL_KEYTYPE_RSA;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class XadesBillingRecordSignerTest extends TestCase
{
    private string $certificatePath;

    protected function setUp(): void
    {
        $this->certificatePath = dirname(__DIR__, 2) . '/Fixtures/certs/test.p12';
    }

    public function testSignsBillingRecordXmlWithPkcs12Certificate(): void
    {
        $generator = new BillingRecordXmlGenerator();
        $record    = new BillingRecord(
            RecordType::Alta,
            '89890001K',
            'FAC-2026-001',
            '09-07-2026',
            'F1',
            '21.00',
            '121.00',
            '2026-07-09T16:00:00+02:00',
            hash: 'ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789',
            issuerName: 'Demo Company SL',
            operationDescription: 'Signed invoice',
        );

        $xml = $generator->generate(
            $record,
            ['name' => 'Demo Company SL'],
            [
                'manufacturer_name' => 'Nowo.tech',
                'manufacturer_nif'  => '89890001K',
                'name'              => 'VerifactuBundle',
                'id'                => '01',
                'version'           => '1.0.0',
            ],
            ['number' => '001'],
        );

        $signer = new XadesBillingRecordSigner(
            new CertificateLoader(),
            $this->certificatePath,
            'test',
        );

        $signedXml = $signer->sign($xml);

        self::assertStringContainsString('Signature', $signedXml);
        self::assertStringContainsString('RegistroAlta', $signedXml);
        self::assertNotSame($xml, $signedXml);
    }

    public function testPemCertificateSignatureVerifiesWithItsCertificate(): void
    {
        $privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($privateKey);
        $csr = openssl_csr_new(['commonName' => 'Verifactu PEM round trip'], $privateKey);
        self::assertNotFalse($csr);
        self::assertNotTrue($csr);
        $x509 = openssl_csr_sign($csr, null, $privateKey, 1);
        self::assertNotFalse($x509);

        $password = bin2hex(random_bytes(8));
        $keyPem   = '';
        $certPem  = '';
        self::assertTrue(openssl_pkey_export($privateKey, $keyPem, $password));
        self::assertTrue(openssl_x509_export($x509, $certPem));

        $pemPath = sys_get_temp_dir() . '/verifactu-sign-' . uniqid('', true) . '.pem';
        file_put_contents($pemPath, $keyPem . "\n" . $certPem);

        try {
            $signedXml = (new XadesBillingRecordSigner(new CertificateLoader(), $pemPath, $password))
                ->sign('<RegistroAlta><IDFactura>FAC-PEM-001</IDFactura></RegistroAlta>');
        } finally {
            @unlink($pemPath);
        }

        $document = new DOMDocument();
        self::assertTrue($document->loadXML($signedXml));

        $dsig      = new XMLSecurityDSig();
        $signature = $dsig->locateSignature($document);
        self::assertNotNull($signature);
        $dsig->canonicalizeSignedInfo();
        self::assertTrue($dsig->validateReference());

        $key = $dsig->locateKey();
        self::assertInstanceOf(XMLSecurityKey::class, $key);
        XMLSecEnc::staticLocateKeyInfo($key, $signature);
        self::assertSame(1, $dsig->verify($key));

        // The embedded certificate is the one generated above (not the private key or the whole PEM file).
        $embedded = $key->getX509Certificate();
        self::assertNotNull($embedded);
        self::assertSame(openssl_x509_fingerprint($x509, 'sha256'), openssl_x509_fingerprint($embedded, 'sha256'));
    }

    public function testSignThrowsWhenCertificatePathIsMissing(): void
    {
        $signer = new XadesBillingRecordSigner(new CertificateLoader(), null, null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('certificate path is required');
        $signer->sign('<RegistroAlta/>');
    }

    public function testSignThrowsWhenXmlIsInvalid(): void
    {
        $signer = new XadesBillingRecordSigner(
            new CertificateLoader(),
            $this->certificatePath,
            'test',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to load billing record XML');
        $signer->sign('not-xml');
    }

    public function testSignThrowsWhenXmlIsEmptyDocument(): void
    {
        $signer = new XadesBillingRecordSigner(
            new CertificateLoader(),
            $this->certificatePath,
            'test',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to load billing record XML');
        // libxml rejects declaration-only / comment-only payloads before documentElement is set
        $signer->sign('<?xml version="1.0"?><!-- empty -->');
    }
}
