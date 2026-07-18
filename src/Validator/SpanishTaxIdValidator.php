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
            default => '',
        };

        return $this->isValidNif($prefix . substr($taxId, 1));
    }

    private function isValidCif(string $taxId): bool
    {
        return (bool) preg_match('/^[ABCDEFGHJNPQRSUVW]\d{7}[0-9A-J]$/', $taxId);
    }
}
