<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Helper;

/**
 * Helper for validation message assertions in unit tests.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class TranslationHelper
{
    /** @var array<string, string> */
    private const TRANSLATIONS = [
        'validation.issuer_nif.invalid'          => 'The issuer tax ID (NIF/CIF/NIE) is invalid.',
        'validation.invoice_series_number.empty' => 'The invoice series and number must not be empty.',
        'validation.issue_date.invalid'          => 'The issue date must use dd-mm-yyyy format.',
        'validation.invoice_type.empty'          => 'The invoice type code is required for alta records.',
        'validation.total_amount.invalid'        => 'The total amount must be a valid number.',
        'validation.total_tax_amount.invalid'    => 'The total tax amount must be a valid number.',
        'validation.generated_at.empty'          => 'The record generation timestamp is required.',
        'validation.xml.parse_failed'            => 'Invalid XML: %errors%',
        'validation.xml.xsd.failed'              => 'XSD validation failed: %errors%',
    ];

    /**
     * @param array<string, string> $parameters
     */
    public static function translate(string $id, array $parameters = [], ?string $domain = null): string
    {
        $message = self::TRANSLATIONS[$id] ?? $id;

        foreach ($parameters as $key => $value) {
            $message           = str_replace($key, (string) $value, $message);
            $keyWithoutPercent = trim($key, '%');
            if ($keyWithoutPercent !== $key) {
                $message = str_replace('%' . $keyWithoutPercent . '%', (string) $value, $message);
            }
        }

        return $message;
    }

    public static function createTranslatorCallback(): callable
    {
        return static fn (string $id, array $parameters = [], ?string $domain = null): string => self::translate($id, $parameters, $domain);
    }
}
