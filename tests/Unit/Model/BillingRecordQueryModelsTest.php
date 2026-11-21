<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Model;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Nowo\VerifactuBundle\Model\AeatEnvironment;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\BillingRecordCriteria;
use Nowo\VerifactuBundle\Model\BillingRecordSort;
use Nowo\VerifactuBundle\Model\BillingRecordView;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Model\StoredBillingRecord;
use Nowo\VerifactuBundle\Repository\BillingRecordQueryPaging;
use PHPUnit\Framework\TestCase;

/**
 * Value objects of the record read port (1.10).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class BillingRecordQueryModelsTest extends TestCase
{
    public function testCriteriaDefaultsAndNormalisation(): void
    {
        $criteria = new BillingRecordCriteria(' 89890001k ');

        self::assertSame('89890001K', $criteria->issuerNif);
        self::assertFalse($criteria->isEmptyScope());
        self::assertSame([[], [], [], null, null, null], [$criteria->statuses, $criteria->recordTypes, $criteria->invoiceTypes, $criteria->invoiceSeriesNumber, $criteria->issuedFrom, $criteria->issuedTo]);
        self::assertSame(BillingRecordSort::PositionDesc, $criteria->sort);

        $filtered = new BillingRecordCriteria(
            '89890001K',
            [StoredBillingRecord::STATUS_PENDING, StoredBillingRecord::STATUS_PENDING, StoredBillingRecord::STATUS_HELD],
            ['Alta', RecordType::Alta, RecordType::Anulacion],
            [' f1', 'F1', 'r2'],
            ' FAC-1',
            new DateTimeImmutable('2026-01-31 23:30', new DateTimeZone('Europe/Madrid')),
            new DateTimeImmutable('2026-02-01'),
            BillingRecordSort::IssueDateAsc,
        );
        self::assertSame([StoredBillingRecord::STATUS_PENDING, StoredBillingRecord::STATUS_HELD], $filtered->statuses);
        self::assertSame([RecordType::Alta, RecordType::Anulacion], $filtered->recordTypes);
        self::assertSame(['F1', 'R2'], $filtered->invoiceTypes);
        self::assertSame('FAC-1', $filtered->invoiceSeriesNumber);
        self::assertSame(['20260131', '20260201'], [$filtered->issuedFrom, $filtered->issuedTo]);
    }

    public function testAScopeThatIsNotANifShapedKeyIsEmpty(): void
    {
        foreach (['', ' ', '89890001K%', 'A_B', '123456789012345678901', "8989\n0001K"] as $scope) {
            self::assertTrue((new BillingRecordCriteria($scope))->isEmptyScope(), $scope);
            self::assertSame('', BillingRecordCriteria::normalizeIssuer($scope), $scope);
        }
        self::assertSame('12345678901234567890', BillingRecordCriteria::normalizeIssuer('12345678901234567890'));
    }

    public function testUnknownStatusOrRecordTypeIsRefused(): void
    {
        try {
            new BillingRecordCriteria('89890001K', ['done']);
            self::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('Unknown submission status "done"', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown record type "alta"');
        new BillingRecordCriteria('89890001K', recordTypes: ['alta']);
    }

    public function testIssueDateKey(): void
    {
        self::assertSame('20260115', BillingRecordCriteria::issueDateKey('15-01-2026'));
        self::assertSame('', BillingRecordCriteria::issueDateKey('2026-01-15'));
        self::assertSame('', BillingRecordCriteria::issueDateKey("15-01-2026\n"));
    }

    public function testViewFromAStoredRecord(): void
    {
        $record = new BillingRecord(RecordType::Anulacion, ' 89890001k', 'FAC-1', '15-01-2026', 'F1', '2.10', '12.10', '2026-01-15T10:00:00+01:00', previousHash: 'AA', hash: 'BB', issuerName: 'Issuer', environment: AeatEnvironment::Production);
        $view   = BillingRecordView::fromStored(new StoredBillingRecord(7, $record, StoredBillingRecord::STATUS_REJECTED, 'CSV', 2, 1100, 'Rejected', 1));

        self::assertSame(['89890001K', 7, RecordType::Anulacion, 'FAC-1', 'AA', 'BB'], [$view->issuerNif, $view->position, $view->recordType, $view->invoiceSeriesNumber, $view->previousHash, $view->hash]);
        self::assertSame([StoredBillingRecord::STATUS_REJECTED, 'CSV', 2, 1100, 'Rejected'], [$view->submissionStatus, $view->submissionReference, $view->submissionAttempts, $view->submissionErrorCode, $view->submissionError]);
        self::assertSame(['Issuer', AeatEnvironment::Production, null, null], [$view->issuerName, $view->environment, $view->recordedAt, $view->submittedAt]);

        $unhashed = BillingRecordView::fromStored(new StoredBillingRecord(1, new BillingRecord(RecordType::Alta, '89890001K', 'FAC-2', '15-01-2026', 'F1', '0.00', '0.00', '2026-01-15T10:00:00+01:00')));
        self::assertSame(['', ''], [$unhashed->previousHash, $unhashed->hash]);
    }

    public function testDistinctInvoicesKeepTheFirstOccurrenceTrimmed(): void
    {
        self::assertSame(['B', 'A', '10', 'a'], BillingRecordQueryPaging::distinctInvoices(['B', 'A', '10', 'B', 'a', 'A ', '10', ' B']));
    }
}
