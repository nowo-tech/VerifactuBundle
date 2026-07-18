<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Validator;

use Nowo\VerifactuBundle\Validator\SpanishTaxIdValidator;
use PHPUnit\Framework\TestCase;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class SpanishTaxIdValidatorTest extends TestCase
{
    private SpanishTaxIdValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SpanishTaxIdValidator();
    }

    public function testValidNif(): void
    {
        self::assertTrue($this->validator->isValid('89890001K'));
    }

    public function testInvalidNif(): void
    {
        self::assertFalse($this->validator->isValid('12345678A'));
    }
}
