<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Validator;

use InvalidArgumentException;
use Nowo\VerifactuBundle\Generator\BillingRecordXmlGenerator;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Tests\Unit\Helper\TranslationHelper;
use Nowo\VerifactuBundle\Validator\XsdValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class XsdValidatorTest extends TestCase
{
    private XsdValidator $validator;

    protected function setUp(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(TranslationHelper::createTranslatorCallback());

        $this->validator = new XsdValidator($translator, true);
    }

    public function testValidateReturnsEmptyWhenDisabled(): void
    {
        $validator = new XsdValidator($this->createMock(TranslatorInterface::class), false);

        self::assertSame([], $validator->validate('<invalid'));
    }

    public function testValidateReturnsParseErrorForMalformedXml(): void
    {
        $errors = $this->validator->validate('<unclosed');

        self::assertCount(1, $errors);
        self::assertStringContainsString('Invalid XML', $errors[0]);
    }

    public function testAssertValidThrowsOnInvalidXml(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->validator->assertValid('<unclosed');
    }

    public function testAssertValidPassesForValidAltaXml(): void
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
            operationDescription: 'Demo invoice',
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

        $this->validator->assertValid($xml, XsdValidator::SCHEMA_REGISTRO_ALTA);
        self::assertSame([], $this->validator->validate($xml, XsdValidator::SCHEMA_REGISTRO_ALTA));
    }

    public function testValidateReturnsSchemaErrorsForInvalidAltaXml(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<RegistroAlta xmlns="https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd">
  <IDVersion>invalid-version</IDVersion>
</RegistroAlta>
XML;

        $errors = $this->validator->validate($xml, XsdValidator::SCHEMA_REGISTRO_ALTA);

        self::assertCount(1, $errors);
    }

    public function testValidateReturnsEmptyForUnknownSchemaType(): void
    {
        self::assertSame([], $this->validator->validate('<root/>', 'unknown_schema'));
    }
}
