<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Client;

use Nowo\VerifactuBundle\Client\SoapEnvelopeBuilder;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use PHPUnit\Framework\TestCase;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class SoapEnvelopeBuilderTest extends TestCase
{
    public function testBuildSubmissionEnvelopeWrapsRecord(): void
    {
        $record = new BillingRecord(
            RecordType::Alta,
            '89890001K',
            'FAC-001',
            '09-07-2026',
            'F1',
            '21.00',
            '121.00',
            '2026-07-09T16:00:00+02:00',
            xml: '<RegistroAlta><Huella>ABC</Huella></RegistroAlta>',
            issuerName: 'Demo SL',
        );

        $xml = (new SoapEnvelopeBuilder())->buildSubmissionEnvelope($record, ['name' => 'Fallback SL']);

        self::assertStringContainsString('RegFactuSistemaFacturacion', $xml);
        self::assertStringContainsString('<sf:NIF>89890001K</sf:NIF>', $xml);
        self::assertStringContainsString('<RegistroAlta><Huella>ABC</Huella></RegistroAlta>', $xml);
    }
}
