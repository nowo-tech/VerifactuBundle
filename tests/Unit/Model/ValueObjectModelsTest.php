<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Model;

use Nowo\VerifactuBundle\Model\InvoiceLine;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Model\TaxBreakdown;
use PHPUnit\Framework\TestCase;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class ValueObjectModelsTest extends TestCase
{
    public function testRecordTypeEnumValues(): void
    {
        self::assertSame('Alta', RecordType::Alta->value);
        self::assertSame('Anulacion', RecordType::Anulacion->value);
        self::assertSame(RecordType::Alta, RecordType::tryFrom('Alta'));
        self::assertNull(RecordType::tryFrom('Unknown'));
    }

    public function testInvoiceLineStoresValues(): void
    {
        $line = new InvoiceLine('Consulting', '1', '100.00', '21.00', '21.00', '121.00');

        self::assertSame('Consulting', $line->description);
        self::assertSame('121.00', $line->totalAmount);
    }

    public function testTaxBreakdownStoresValues(): void
    {
        $breakdown = new TaxBreakdown('01', '21.00', '100.00', '21.00');

        self::assertSame('01', $breakdown->taxType);
        self::assertSame('21.00', $breakdown->taxAmount);
    }
}
