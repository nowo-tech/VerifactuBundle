<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\VerifactuBundle\Entity\BillingRecordEntry;
use PHPUnit\Framework\TestCase;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class BillingRecordEntryTest extends TestCase
{
    public function testAccessors(): void
    {
        $entry = new BillingRecordEntry(' 89890001k ', 3, 'Alta', 'F-3', '09-07-2026', 'F1', '21.00', '121.00', '2026-07-09T16:00:00+02:00', 'PREV', 'HASH', '<xml/>', '<signed/>', 'Issuer', 'accepted');

        self::assertSame('89890001K', $entry->getIssuerNif());
        self::assertSame(3, $entry->getPosition());
        self::assertSame('Alta', $entry->getRecordType());
        self::assertSame('F-3', $entry->getInvoiceSeriesNumber());
        self::assertSame('09-07-2026', $entry->getIssueDate());
        self::assertSame('F1', $entry->getInvoiceType());
        self::assertSame('21.00', $entry->getTotalTaxAmount());
        self::assertSame('121.00', $entry->getTotalAmount());
        self::assertSame('2026-07-09T16:00:00+02:00', $entry->getGeneratedAt());
        self::assertSame('PREV', $entry->getPreviousHash());
        self::assertSame('HASH', $entry->getHash());
        self::assertSame('<xml/>', $entry->getXml());
        self::assertSame('<signed/>', $entry->getSignedXml());
        self::assertSame('Issuer', $entry->getIssuerName());
        self::assertSame('accepted', $entry->getSubmissionStatus());
        self::assertNull($entry->getSubmissionReference());
        self::assertLessThanOrEqual((new DateTimeImmutable())->getTimestamp(), $entry->getCreatedAt()->getTimestamp());
    }
}
