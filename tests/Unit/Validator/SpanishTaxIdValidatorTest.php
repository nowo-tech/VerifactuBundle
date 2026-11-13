<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Validator;

use Nowo\VerifactuBundle\Validator\SpanishTaxIdValidator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

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

    /**
     * REQ-SEC-006 item 5: the id patterns carry /D, so a trailing newline is not accepted by `$`.
     * isValid() trims its input, so the private checks are exercised directly.
     */
    public function testIdPatternsRejectATrailingNewline(): void
    {
        foreach (['isValidNif' => '89890001K', 'isValidNie' => 'X1234567L', 'isValidCif' => 'A58818501'] as $method => $valid) {
            $check = new ReflectionMethod($this->validator, $method);

            self::assertTrue($check->invoke($this->validator, $valid), $method . ' accepts ' . $valid);
            self::assertFalse($check->invoke($this->validator, $valid . "\n"), $method . ' rejects a trailing newline');
        }
    }
}
