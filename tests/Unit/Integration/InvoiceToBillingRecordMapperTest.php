<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Integration;

use Nowo\VerifactuBundle\Integration\InvoiceDraft;
use Nowo\VerifactuBundle\Integration\InvoiceToBillingRecordMapper;
use Nowo\VerifactuBundle\Model\RecordType;
use PHPUnit\Framework\TestCase;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class InvoiceToBillingRecordMapperTest extends TestCase
{
    public function testMapsInvoiceDraftToBillingRecord(): void
    {
        $mapper = new InvoiceToBillingRecordMapper();
        $draft  = new InvoiceDraft(
            '89890001K',
            'FAC-2026-100',
            '09-07-2026',
            '21.00',
            '121.00',
            '2026-07-09T16:00:00+02:00',
            RecordType::Alta,
            'F1',
            'Acme SL',
            'Consulting services',
        );

        $record = $mapper->map($draft);

        self::assertSame(RecordType::Alta, $record->recordType);
        self::assertSame('89890001K', $record->issuerNif);
        self::assertSame('FAC-2026-100', $record->invoiceSeriesNumber);
        self::assertSame('Acme SL', $record->issuerName);
        self::assertSame('Consulting services', $record->operationDescription);
    }
}
