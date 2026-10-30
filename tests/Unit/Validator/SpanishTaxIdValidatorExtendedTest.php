<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Validator;

use Nowo\VerifactuBundle\Validator\SpanishTaxIdValidator;
use PHPUnit\Framework\TestCase;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class SpanishTaxIdValidatorExtendedTest extends TestCase
{
    private SpanishTaxIdValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SpanishTaxIdValidator();
    }

    public function testValidNieIsAccepted(): void
    {
        self::assertTrue($this->validator->isValid('X1234567L'));
    }

    public function testValidCifIsAccepted(): void
    {
        self::assertTrue($this->validator->isValid('B12345674'));
        self::assertTrue($this->validator->isValid('Q2826000H'));
        self::assertTrue($this->validator->isValid('P1234567D'));
        self::assertTrue($this->validator->isValid('G12345674'));
        self::assertTrue($this->validator->isValid('G1234567D'));
    }

    public function testCifWithWrongControlCharacterIsRejected(): void
    {
        self::assertFalse($this->validator->isValid('B12345678'));
        self::assertFalse($this->validator->isValid('B1234567D'));
        self::assertFalse($this->validator->isValid('P12345674'));
        self::assertFalse($this->validator->isValid('Q2826000A'));
        self::assertFalse($this->validator->isValid('G12345675'));
    }

    public function testInvalidTaxIdIsRejected(): void
    {
        self::assertFalse($this->validator->isValid('NOT-A-TAX-ID'));
    }

    public function testEmptyTaxIdIsRejected(): void
    {
        self::assertFalse($this->validator->isValid(''));
    }

    public function testValidNieWithYAndZPrefixesAreAccepted(): void
    {
        self::assertTrue($this->validator->isValid('Y0000000Z'));
        self::assertTrue($this->validator->isValid('Z0000000M'));
    }
}
