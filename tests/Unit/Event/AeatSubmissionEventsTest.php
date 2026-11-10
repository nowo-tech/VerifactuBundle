<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Event;

use Nowo\VerifactuBundle\Event\AfterAeatSubmissionEvent;
use Nowo\VerifactuBundle\Event\BeforeAeatSubmissionEvent;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use PHPUnit\Framework\TestCase;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class AeatSubmissionEventsTest extends TestCase
{
    public function testBeforeEventExposesTheRecord(): void
    {
        $record = $this->record();

        self::assertSame($record, (new BeforeAeatSubmissionEvent($record))->getRecord());
    }

    public function testAfterEventExposesRecordAndResult(): void
    {
        $record = $this->record();
        $event  = new AfterAeatSubmissionEvent($record, ['success' => true, 'reference' => 'CSV-1']);

        self::assertSame($record, $event->getRecord());
        self::assertSame(['success' => true, 'reference' => 'CSV-1'], $event->getSubmission());
        self::assertTrue($event->isSuccessful());
    }

    public function testAfterEventIsNotSuccessfulWithoutATrueSuccessFlag(): void
    {
        self::assertFalse((new AfterAeatSubmissionEvent($this->record(), ['success' => false]))->isSuccessful());
        self::assertFalse((new AfterAeatSubmissionEvent($this->record(), ['success' => 1]))->isSuccessful());
        self::assertFalse((new AfterAeatSubmissionEvent($this->record(), []))->isSuccessful());
    }

    private function record(): BillingRecord
    {
        return new BillingRecord(
            RecordType::Alta,
            '89890001K',
            'FAC-001',
            '09-07-2026',
            'F1',
            '21.00',
            '121.00',
            '2026-07-09T16:00:00+02:00',
        );
    }
}
