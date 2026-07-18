<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Event;

use Nowo\VerifactuBundle\Event\AfterBillingRecordGeneratedEvent;
use Nowo\VerifactuBundle\Event\BeforeBillingRecordGenerationEvent;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use PHPUnit\Framework\TestCase;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class VerifactuEventsTest extends TestCase
{
    public function testBeforeEventAllowsRecordMutation(): void
    {
        $original = new BillingRecord(
            RecordType::Alta,
            '89890001K',
            'FAC-001',
            '09-07-2026',
            'F1',
            '21.00',
            '121.00',
            '2026-07-09T16:00:00+02:00',
        );
        $event = new BeforeBillingRecordGenerationEvent($original);

        self::assertSame($original, $event->getRecord());
    }

    public function testAfterEventExposesGeneratedRecord(): void
    {
        $record = new BillingRecord(
            RecordType::Alta,
            '89890001K',
            'FAC-001',
            '09-07-2026',
            'F1',
            '21.00',
            '121.00',
            '2026-07-09T16:00:00+02:00',
            hash: str_repeat('A', 64),
        );

        self::assertSame($record, (new AfterBillingRecordGeneratedEvent($record))->getRecord());
    }
}
