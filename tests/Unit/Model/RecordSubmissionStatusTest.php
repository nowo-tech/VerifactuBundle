<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Model;

use Nowo\VerifactuBundle\Model\RecordSubmissionOutcome;
use Nowo\VerifactuBundle\Model\RecordSubmissionStatus;
use Nowo\VerifactuBundle\Model\RecordType;
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

        // A confirmed duplicate, by record type (LJ-L2).
        self::assertSame(RecordSubmissionStatus::Accepted, RecordSubmissionStatus::fromDuplicate('Correcta'));
        self::assertSame(RecordSubmissionStatus::AcceptedWithErrors, RecordSubmissionStatus::fromDuplicate('AceptadaConErrores'));
        self::assertSame(RecordSubmissionStatus::Accepted, RecordSubmissionStatus::fromDuplicate('Anulada', RecordType::Alta), 'Registered, then annulled');
        self::assertSame(RecordSubmissionStatus::Accepted, RecordSubmissionStatus::fromDuplicate(' Anulada ', RecordType::Anulacion), 'The anulación is registered');
        self::assertNull(RecordSubmissionStatus::fromDuplicate('Correcta', RecordType::Anulacion));
        self::assertNull(RecordSubmissionStatus::fromDuplicate('Otra'));

        self::assertTrue(RecordSubmissionStatus::AcceptedWithErrors->isRegistered());
        self::assertFalse(RecordSubmissionStatus::Rejected->isRegistered());
        self::assertFalse(RecordSubmissionStatus::Unconfirmed->isRegistered());
    }

    public function testStoreStatuses(): void
    {
        $stored = new StoredBillingRecord(1, TestProcessorFactory::record('F-1'));

        self::assertSame(
            [StoredBillingRecord::STATUS_ACCEPTED, StoredBillingRecord::STATUS_ACCEPTED_WITH_ERRORS, StoredBillingRecord::STATUS_REJECTED, StoredBillingRecord::STATUS_UNCONFIRMED, StoredBillingRecord::STATUS_PENDING],
            array_map(static fn (?RecordSubmissionStatus $status): string => (new RecordSubmissionOutcome($stored, $status))->storeStatus(), [RecordSubmissionStatus::Accepted, RecordSubmissionStatus::AcceptedWithErrors, RecordSubmissionStatus::Rejected, RecordSubmissionStatus::Unconfirmed, null]),
        );
    }
}
