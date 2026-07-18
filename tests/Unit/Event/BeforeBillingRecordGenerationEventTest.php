<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Event;

use Nowo\VerifactuBundle\Event\BeforeBillingRecordGenerationEvent;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use PHPUnit\Framework\TestCase;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class BeforeBillingRecordGenerationEventTest extends TestCase
{
    public function testGetAndSetRecord(): void
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

        $mutated = new BillingRecord(
            RecordType::Alta,
            '89890001K',
            'FAC-001',
            '09-07-2026',
            'F1',
            '21.00',
            '121.00',
            '2026-07-09T16:00:00+02:00',
            operationDescription: 'Updated',
        );

        $event = new BeforeBillingRecordGenerationEvent($original);
        self::assertSame($original, $event->getRecord());

        $event->setRecord($mutated);
        self::assertSame($mutated, $event->getRecord());
        self::assertSame('Updated', $event->getRecord()->operationDescription);
    }
}
