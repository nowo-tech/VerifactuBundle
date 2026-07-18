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
        self::assertTrue($this->validator->isValid('B12345678'));
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
