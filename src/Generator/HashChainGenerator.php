<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Generator;

use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Computes AEAT SHA-256 fingerprints and hash chains for billing records.
 *
 * @see Orden HAC/1177/2024, artículo 13 — field order and concatenation format.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsAlias(id: self::SERVICE_NAME, public: true)]
final class HashChainGenerator
{
    public const SERVICE_NAME = 'nowo_verifactu.generator.hash_chain_generator';

    /**
     * Builds the canonical input string for a billing record fingerprint.
     */
    public function buildInputString(BillingRecord $record): string
    {
        if ($record->recordType === RecordType::Alta) {
            return $this->buildAltaInputString($record);
        }

        return $this->buildAnulacionInputString($record);
    }

    /**
     * Computes the uppercase SHA-256 hex fingerprint for a billing record.
     */
    public function computeHash(BillingRecord $record): string
    {
        $input = $this->buildInputString($record);

        return strtoupper(hash('sha256', $input));
    }

    /**
     * Verifies that a stored hash matches the record data.
     */
    public function verifyHash(BillingRecord $record, string $expectedHash): bool
    {
        return hash_equals(strtoupper($expectedHash), $this->computeHash($record));
    }

    /**
     * Verifies chain integrity: previous hash in record matches stored previous hash.
     */
    public function verifyChainLink(BillingRecord $record, ?string $storedPreviousHash): bool
    {
        $recordPrevious = $record->previousHash ?? '';

        if ($storedPreviousHash === null) {
            return $recordPrevious === '';
        }

        return hash_equals(strtoupper($storedPreviousHash), strtoupper($recordPrevious));
    }

    private function buildAltaInputString(BillingRecord $record): string
    {
        $fields = [
            'IDEmisorFactura'          => $this->normalize($record->issuerNif),
            'NumSerieFactura'          => $this->normalize($record->invoiceSeriesNumber),
            'FechaExpedicionFactura'   => $this->normalize($record->issueDate),
            'TipoFactura'              => $this->normalize($record->invoiceType),
            'CuotaTotal'               => $this->normalizeAmount($record->totalTaxAmount),
            'ImporteTotal'             => $this->normalizeAmount($record->totalAmount),
            'Huella'                   => $this->normalize($record->previousHash ?? ''),
            'FechaHoraHusoGenRegistro' => $this->normalize($record->generatedAt),
        ];

        return $this->concatenateFields($fields);
    }

    private function buildAnulacionInputString(BillingRecord $record): string
    {
        // RegistroAnulacion/IDFactura uses the *Anulada element names (AEAT hash spec v0.1.2, §3.b).
        $fields = [
            'IDEmisorFacturaAnulada'        => $this->normalize($record->issuerNif),
            'NumSerieFacturaAnulada'        => $this->normalize($record->invoiceSeriesNumber),
            'FechaExpedicionFacturaAnulada' => $this->normalize($record->issueDate),
            'Huella'                        => $this->normalize($record->previousHash ?? ''),
            'FechaHoraHusoGenRegistro'      => $this->normalize($record->generatedAt),
        ];

        return $this->concatenateFields($fields);
    }

    /**
     * @param array<string, string> $fields
     */
    private function concatenateFields(array $fields): string
    {
        $parts = [];
        foreach ($fields as $name => $value) {
            $parts[] = $name . '=' . $value;
        }

        return implode('&', $parts);
    }

    private function normalize(string $value): string
    {
        return trim($value);
    }

    /**
     * Normalizes a decimal amount without going through float.
     *
     * The AEAT hash specification states that trailing zeros in the decimals are not significant
     * ("123.1" and "123.10" are equally valid), so only insignificant zeros are stripped from the
     * exact string written into the XML. Anything that is not a plain decimal is hashed as-is.
     */
    private function normalizeAmount(string $value): string
    {
        $trimmed = trim($value);
        if (preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $trimmed, $matches) !== 1 || ($matches[2] === '' && ($matches[3] ?? '') === '')) {
            return $trimmed;
        }

        $sign     = $matches[1] === '-' ? '-' : '';
        $integer  = ltrim($matches[2], '0');
        $integer  = $integer === '' ? '0' : $integer;
        $decimals = rtrim($matches[3] ?? '', '0');

        return $sign . $integer . ($decimals !== '' ? '.' . $decimals : '');
    }
}
