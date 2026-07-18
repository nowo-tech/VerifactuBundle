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
        $fields = [
            'IDEmisorFactura'          => $this->normalize($record->issuerNif),
            'NumSerieFactura'          => $this->normalize($record->invoiceSeriesNumber),
            'FechaExpedicionFactura'   => $this->normalize($record->issueDate),
            'Huella'                   => $this->normalize($record->previousHash ?? ''),
            'FechaHoraHusoGenRegistro' => $this->normalize($record->generatedAt),
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

    private function normalizeAmount(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (!is_numeric($trimmed)) {
            return $trimmed;
        }

        $normalized = rtrim(rtrim(number_format((float) $trimmed, 6, '.', ''), '0'), '.');

        return str_contains($normalized, '.') ? $normalized : $normalized;
    }
}
