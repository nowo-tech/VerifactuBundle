<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Model;

use Nowo\VerifactuBundle\Integration\InvoiceDraft;
use Nowo\VerifactuBundle\Integration\InvoiceToBillingRecordMapper;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\Recipient;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Model\RectificationAmount;
use Nowo\VerifactuBundle\Model\RectifiedInvoice;
use PHPUnit\Framework\TestCase;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class CompletenessModelsTest extends TestCase
{
    public function testValueObjectsAndTheirSurvivalThroughWithersAndTheMapper(): void
    {
        $byNif     = Recipient::withNif('Cliente SL', 'B12345674');
        $foreign   = Recipient::withForeignId('Customer', Recipient::ID_TYPE_PASSPORT, 'P1', 'US');
        $rectified = new RectifiedInvoice('89890001K', 'FAC-1', '01-07-2026');
        $amount    = new RectificationAmount('100', '21');

        self::assertSame(['Cliente SL', 'B12345674', null], [$byNif->name, $byNif->nif, $byNif->foreignId]);
        self::assertSame(['03', 'P1', 'US', null], [$foreign->foreignIdType, $foreign->foreignId, $foreign->countryCode, $foreign->nif]);
        self::assertSame(['89890001K', 'FAC-1', '01-07-2026'], [$rectified->issuerNif, $rectified->invoiceSeriesNumber, $rectified->issueDate]);
        self::assertNull($amount->surcharge);

        $record = (new InvoiceToBillingRecordMapper())->map(new InvoiceDraft(
            '89890001K',
            'R-1',
            '09-07-2026',
            '21.00',
            '121.00',
            '',
            RecordType::Alta,
            'R1',
            recipients: [$byNif, $foreign],
            rectificationType: BillingRecord::RECTIFICATION_BY_SUBSTITUTION,
            rectifiedInvoices: [$rectified],
            substitutedInvoices: [],
            rectificationAmount: $amount,
            operationDate: '30-06-2026',
        ));
        $copy = $record->withHash('H', 'P')->withXml('<x/>')->withSignedXml('<s/>');

        self::assertSame([$byNif, $foreign], $copy->recipients);
        self::assertSame('S', $copy->rectificationType);
        self::assertSame([$rectified], $copy->rectifiedInvoices);
        self::assertSame($amount, $copy->rectificationAmount);
        self::assertSame('30-06-2026', $copy->operationDate);
        self::assertSame(['H', 'P', '<x/>', '<s/>'], [$copy->hash, $copy->previousHash, $copy->xml, $copy->signedXml]);
    }
}
