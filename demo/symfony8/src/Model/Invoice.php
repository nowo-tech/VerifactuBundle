<?php

declare(strict_types=1);

namespace App\Model;

use Nowo\VerifactuBundle\Model\RecordType;

/**
 * Demo invoice entity representing a Nowo billing document before Veri*Factu processing.
 */
final class Invoice
{
    public function __construct(
        public readonly string $number,
        public readonly string $issueDate,
        public readonly string $totalTaxAmount,
        public readonly string $totalAmount,
        public readonly RecordType $recordType = RecordType::Alta,
        public readonly string $invoiceType = 'F1',
        public readonly ?string $description = null,
    ) {
    }
}
