<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Support;

use Nowo\VerifactuBundle\Validator\XsdValidator;

/**
 * Forces XSD validation failures for BillingRecordProcessor coverage.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class FailingXsdValidator extends XsdValidator
{
    /**
     * @return list<string>
     */
    public function validate(string $xml, string $schemaType = self::SCHEMA_REGISTRO_ALTA): array
    {
        return ['XSD broken'];
    }
}
