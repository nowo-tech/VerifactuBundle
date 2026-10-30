<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Validator;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Validates Spanish NIF/CIF/NIE tax identifiers.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsAlias(id: self::SERVICE_NAME, public: true)]
final class SpanishTaxIdValidator
{
    public const SERVICE_NAME = 'nowo_verifactu.validator.spanish_tax_id';

    /**
     * Whether the value is a valid Spanish NIF, NIE or CIF (format and check digit).
     */
    public function isValid(string $taxId): bool
    {
        $taxId = strtoupper(trim($taxId));

        if ($taxId === '') {
            return false;
        }

        if ($this->isValidNif($taxId)) {
            return true;
        }

        if ($this->isValidNie($taxId)) {
            return true;
        }

        return $this->isValidCif($taxId);
    }

    private function isValidNif(string $taxId): bool
    {
        if (!preg_match('/^\d{8}[A-Z]$/', $taxId)) {
            return false;
        }

        $letters = 'TRWAGMYFPDXBNJZSQVHLCKE';
        $number  = (int) substr($taxId, 0, 8);
        $letter  = $letters[$number % 23];

        return $letter === $taxId[8];
    }

    private function isValidNie(string $taxId): bool
    {
        if (!preg_match('/^[XYZ]\d{7}[A-Z]$/', $taxId)) {
            return false;
        }

        $prefix = match ($taxId[0]) {
            'X'     => '0',
            'Y'     => '1',
            'Z'     => '2',
            default => '', // @codeCoverageIgnore
        };

        return $this->isValidNif($prefix . substr($taxId, 1));
    }

    /**
     * CIF: entity letter, 7 digits and a control character computed from the digits. P, Q, R, S, W and N entities
     * use the control letter; A, B, E and H use the control digit; the other entity letters accept either.
     */
    private function isValidCif(string $taxId): bool
    {
        if (!preg_match('/^[ABCDEFGHJNPQRSUVW]\d{7}[0-9A-J]$/', $taxId)) {
            return false;
        }

        $sum = 0;
        for ($position = 1; $position <= 7; ++$position) {
            $digit = (int) $taxId[$position];
            if ($position % 2 === 1) {
                $doubled = $digit * 2;
                $sum += intdiv($doubled, 10) + $doubled % 10;
            } else {
                $sum += $digit;
            }
        }

        $controlDigit  = (10 - $sum % 10) % 10;
        $controlLetter = 'JABCDEFGHI'[$controlDigit];
        $control       = $taxId[8];

        return match (true) {
            str_contains('PQRSWN', $taxId[0]) => $control === $controlLetter,
            str_contains('ABEH', $taxId[0])   => $control === (string) $controlDigit,
            default                           => $control === (string) $controlDigit || $control === $controlLetter,
        };
    }
}
