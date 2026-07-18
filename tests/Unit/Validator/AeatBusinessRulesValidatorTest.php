<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Validator;

use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Validator\AeatBusinessRulesValidator;
use Nowo\VerifactuBundle\Validator\SpanishTaxIdValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class AeatBusinessRulesValidatorTest extends TestCase
{
    private AeatBusinessRulesValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new AeatBusinessRulesValidator(
            new SpanishTaxIdValidator(),
            new IdentityTranslator(),
        );
    }

    public function testValidRecordReturnsNoErrors(): void
    {
        $errors = $this->validator->validate($this->validRecord());

        self::assertSame([], $errors);
    }

    public function testInvalidNifReturnsError(): void
    {
        $record = new BillingRecord(
            RecordType::Alta,
            'INVALID',
            'FAC-001',
            '09-07-2026',
            'F1',
            '21.00',
            '121.00',
            '2026-07-09T16:00:00+02:00',
        );

        self::assertNotEmpty($this->validator->validate($record));
    }

    private function validRecord(): BillingRecord
    {
        return new BillingRecord(
            RecordType::Alta,
            '89890001K',
            'FAC-001',
            '09-07-2026',
            'F1',
            '21.00',
            '121.00',
            '2026-07-09T16:00:00+02:00',
        );
    }
}
