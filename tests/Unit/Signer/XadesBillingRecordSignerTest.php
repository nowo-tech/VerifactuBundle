<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Signer;

use InvalidArgumentException;
use Nowo\VerifactuBundle\Certificate\CertificateLoader;
use Nowo\VerifactuBundle\Generator\BillingRecordXmlGenerator;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Signer\XadesBillingRecordSigner;
use PHPUnit\Framework\TestCase;

use function dirname;

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
