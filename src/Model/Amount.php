<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Model;

use function strlen;

/**
 * The one amount string that a billing record carries in its XML, its fingerprint (Huella) and its QR code.
 *
 * AEAT "Detalle de las especificaciones técnicas para generación de la huella o hash de los registros de facturación"
 * v0.1.2 (27/08/2024), §3: the hashed values must hold "la misma información contenida en el campo correspondiente del
 * fichero XML", and numeric fields with one or two decimals are equally valid ("123.1" and "123.10"). Writing the same
 * string in both places makes the record correct however the AEAT reads it. The canonical form always has two
 * decimals ("12.30", "0.00", "-5.00"), like the AEAT examples, so it stays within the explicit "una o dos posiciones en
 * los decimales" wording also for whole amounts.
 *
 * Pure string operations, never through float. A value that is not a plain decimal (or has a non-zero third decimal)
 * is returned trimmed and unchanged, so the business-rules validator reports it.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class Amount
{
    private const DECIMAL_PATTERN = '/^([+-]?)(\d*)(?:\.(\d*))?$/D';

    /**
     * Returns the canonical amount: no sign for zero or positive values, no leading zeros, exactly two decimals.
     */
    public static function canonical(string $value): string
    {
        $parts = self::parse($value);
        if ($parts === null) {
            return trim($value);
        }

        [$negative, $integer, $decimals] = $parts;
        $decimals                        = rtrim($decimals, '0');
        if (strlen($decimals) > 2) {
            return trim($value);
        }

        $decimals = str_pad($decimals, 2, '0');
        $negative = $negative && ($integer !== '0' || $decimals !== '00');

        return ($negative ? '-' : '') . $integer . '.' . $decimals;
    }

    /**
     * Returns the amount without insignificant zeros ("12.30" → "12.3", "0.00" → "0"): the form VerifactuBundle
     * 1.0 – 1.4.1 hashed. Only used to verify fingerprints written by those versions.
     */
    public static function withoutTrailingZeros(string $value): string
    {
        $parts = self::parse($value);
        if ($parts === null) {
            return trim($value);
        }

        [$negative, $integer, $decimals] = $parts;
        $decimals                        = rtrim($decimals, '0');

        return ($negative ? '-' : '') . $integer . ($decimals !== '' ? '.' . $decimals : '');
    }

    /**
     * @return array{bool, string, string}|null sign, integer part without leading zeros, raw decimals
     */
    private static function parse(string $value): ?array
    {
        if (preg_match(self::DECIMAL_PATTERN, trim($value), $matches) !== 1 || ($matches[2] === '' && ($matches[3] ?? '') === '')) {
            return null;
        }

        $integer = ltrim($matches[2], '0');

        return [$matches[1] === '-', $integer === '' ? '0' : $integer, $matches[3] ?? ''];
    }
}
