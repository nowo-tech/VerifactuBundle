<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Model;

use Nowo\VerifactuBundle\Model\RecordSubmissionOutcome;
use Nowo\VerifactuBundle\Model\RecordSubmissionStatus;
use Nowo\VerifactuBundle\Model\StoredBillingRecord;
use Nowo\VerifactuBundle\Tests\Support\TestProcessorFactory;
use PHPUnit\Framework\TestCase;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class RecordSubmissionStatusTest extends TestCase
{
    public function testAeatValues(): void
    {
        self::assertSame(RecordSubmissionStatus::Accepted, RecordSubmissionStatus::fromAeat(' Correcto '));
        self::assertSame(RecordSubmissionStatus::AcceptedWithErrors, RecordSubmissionStatus::fromAeat('AceptadoConErrores'));
        self::assertSame(RecordSubmissionStatus::Rejected, RecordSubmissionStatus::fromAeat('Incorrecto'));
        self::assertNull(RecordSubmissionStatus::fromAeat('Correcta'));

        self::assertSame(RecordSubmissionStatus::Accepted, RecordSubmissionStatus::fromDuplicate('Correcta'));
        self::assertSame(RecordSubmissionStatus::AcceptedWithErrors, RecordSubmissionStatus::fromDuplicate('AceptadaConErrores'));
        self::assertSame(RecordSubmissionStatus::Rejected, RecordSubmissionStatus::fromDuplicate('Anulada'));

        self::assertTrue(RecordSubmissionStatus::AcceptedWithErrors->isRegistered());
        self::assertFalse(RecordSubmissionStatus::Rejected->isRegistered());
    }

    public function testStoreStatuses(): void
    {
        $stored = new StoredBillingRecord(1, TestProcessorFactory::record('F-1'));

        self::assertSame(
            [StoredBillingRecord::STATUS_ACCEPTED, StoredBillingRecord::STATUS_ACCEPTED_WITH_ERRORS, StoredBillingRecord::STATUS_REJECTED, StoredBillingRecord::STATUS_PENDING],
            array_map(static fn (?RecordSubmissionStatus $status): string => (new RecordSubmissionOutcome($stored, $status))->storeStatus(), [RecordSubmissionStatus::Accepted, RecordSubmissionStatus::AcceptedWithErrors, RecordSubmissionStatus::Rejected, null]),
        );
    }
}
