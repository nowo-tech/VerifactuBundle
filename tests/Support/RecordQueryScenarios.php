<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Support;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\VerifactuBundle\Model\AeatEnvironment;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\BillingRecordCriteria;
use Nowo\VerifactuBundle\Model\BillingRecordSort;
use Nowo\VerifactuBundle\Model\BillingRecordView;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Model\StoredBillingRecord;
use Nowo\VerifactuBundle\Repository\BillingRecordQueryInterface;
use Nowo\VerifactuBundle\Repository\BillingRecordStoreInterface;

/**
 * BillingRecordQueryInterface scenarios (1.10), shared by every implementation and host: the in-memory store, the
 * Doctrine store of a standard host (attributes), of a DDD host (bundle XML mapping) and of a host that maps the
 * bundle entities itself on a second entity manager (SQLite), and the server databases (MySQL, MariaDB, PostgreSQL;
 * tests/Concurrency). The using class provides an empty record store before every test.
 *
 * Seeded chain of ISSUER (positions): 1 FAC-001 Alta F1 15-01-2026, 2 FAC-002 Alta F1 03-02-2026 (accepted),
 * 3 FAC-001 Anulacion F1 15-01-2026, 4 fac-001 Alta F2 20-12-2025 (another invoice: case differs), 5 R-001 Alta R1
 * 01-03-2026 (failed). OTHER_ISSUER has one record, FAC-001.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
trait RecordQueryScenarios
{
    private const RQ_ISSUER = '89890001K';

    private const RQ_OTHER_ISSUER = 'B12345674';

    abstract protected function rqStore(): BillingRecordStoreInterface;

    abstract protected function rqQuery(): BillingRecordQueryInterface;

    public function testThePageIsNewestFirstByDefaultAndScopedToTheIssuer(): void
    {
        $this->rqSeed();
        $query = $this->rqQuery();

        self::assertSame([5, 4, 3, 2, 1], $this->rqPositions($query->page(new BillingRecordCriteria(self::RQ_ISSUER))));
        self::assertSame(5, $query->count(new BillingRecordCriteria(self::RQ_ISSUER)));
        self::assertSame(5, $query->count(new BillingRecordCriteria(' 89890001k ')));
        self::assertSame([1], $this->rqPositions($query->page(new BillingRecordCriteria(self::RQ_OTHER_ISSUER))));
        foreach ($query->page(new BillingRecordCriteria(self::RQ_ISSUER)) as $view) {
            self::assertSame(self::RQ_ISSUER, $view->issuerNif);
        }
    }

    public function testPagingAndOrders(): void
    {
        $this->rqSeed();
        $query = $this->rqQuery();

        self::assertSame([4, 3], $this->rqPositions($query->page(new BillingRecordCriteria(self::RQ_ISSUER), 1, 2)));
        self::assertSame([], $query->page(new BillingRecordCriteria(self::RQ_ISSUER), 5, 10));
        self::assertSame([1, 2, 3, 4, 5], $this->rqPositions($query->page(new BillingRecordCriteria(self::RQ_ISSUER, sort: BillingRecordSort::PositionAsc))));
        self::assertSame([4, 1, 3, 2, 5], $this->rqPositions($query->page(new BillingRecordCriteria(self::RQ_ISSUER, sort: BillingRecordSort::IssueDateAsc))));
        self::assertSame([5, 2, 3, 1, 4], $this->rqPositions($query->page(new BillingRecordCriteria(self::RQ_ISSUER, sort: BillingRecordSort::IssueDateDesc))));
        self::assertSame([1, 3], $this->rqPositions($query->page(new BillingRecordCriteria(self::RQ_ISSUER, sort: BillingRecordSort::IssueDateAsc), 1, 2)));
    }

    public function testFilters(): void
    {
        $this->rqSeed();
        $query = $this->rqQuery();

        $accepted = new BillingRecordCriteria(self::RQ_ISSUER, statuses: [StoredBillingRecord::STATUS_ACCEPTED]);
        self::assertSame([2], $this->rqPositions($query->page($accepted)));
        self::assertSame(1, $query->count($accepted));
        self::assertSame([4, 3, 1], $this->rqPositions($query->page(new BillingRecordCriteria(self::RQ_ISSUER, statuses: [StoredBillingRecord::STATUS_PENDING]))));
        self::assertSame([5, 2], $this->rqPositions($query->page(new BillingRecordCriteria(self::RQ_ISSUER, statuses: [StoredBillingRecord::STATUS_ACCEPTED, StoredBillingRecord::STATUS_FAILED]))));
        self::assertSame([3], $this->rqPositions($query->page(new BillingRecordCriteria(self::RQ_ISSUER, recordTypes: [RecordType::Anulacion]))));
        self::assertSame([4], $this->rqPositions($query->page(new BillingRecordCriteria(self::RQ_ISSUER, invoiceTypes: ['f2']))));
        self::assertSame([5, 4], $this->rqPositions($query->page(new BillingRecordCriteria(self::RQ_ISSUER, invoiceTypes: ['F2', 'R1']))));

        // Exact invoice number: "fac-001" (position 4) is another invoice, whatever the collation
        $invoice = new BillingRecordCriteria(self::RQ_ISSUER, invoiceSeriesNumber: 'FAC-001');
        self::assertSame([3, 1], $this->rqPositions($query->page($invoice)));
        self::assertSame(2, $query->count($invoice));
        // The input is trimmed like the hash and the store do (YA-1): the padded number is the same invoice
        self::assertSame(2, $query->count(new BillingRecordCriteria(self::RQ_ISSUER, invoiceSeriesNumber: ' FAC-001 ')));
        self::assertSame(0, $query->count(new BillingRecordCriteria(self::RQ_ISSUER, invoiceSeriesNumber: 'FAC-001 x')));
        self::assertSame([], $query->page(new BillingRecordCriteria(self::RQ_ISSUER, invoiceSeriesNumber: 'FAC-404')));

        $january = new BillingRecordCriteria(self::RQ_ISSUER, issuedFrom: new DateTimeImmutable('2026-01-01'), issuedTo: new DateTimeImmutable('2026-02-03 23:00'));
        self::assertSame([3, 2, 1], $this->rqPositions($query->page($january)));
        self::assertSame(3, $query->count($january));
        self::assertSame([5, 2], $this->rqPositions($query->page(new BillingRecordCriteria(self::RQ_ISSUER, issuedFrom: new DateTimeImmutable('2026-02-01')))));
        self::assertSame([4], $this->rqPositions($query->page(new BillingRecordCriteria(self::RQ_ISSUER, issuedTo: new DateTimeImmutable('2025-12-31')))));
        $inverted = new BillingRecordCriteria(self::RQ_ISSUER, issuedFrom: new DateTimeImmutable('2026-03-01'), issuedTo: new DateTimeImmutable('2026-01-01'));
        self::assertSame([], $query->page($inverted));
        self::assertSame(0, $query->count($inverted));

        $combined = new BillingRecordCriteria(self::RQ_ISSUER, [StoredBillingRecord::STATUS_PENDING], [RecordType::Alta], ['F1'], 'FAC-001', new DateTimeImmutable('2026-01-15'), new DateTimeImmutable('2026-01-15'));
        self::assertSame([1], $this->rqPositions($query->page($combined)));
    }

    public function testAnEmptyOrMalformedScopeReturnsNothing(): void
    {
        $this->rqSeed();
        $query = $this->rqQuery();

        foreach (['', '   ', "89890001K' OR '1'='1", '%', '89890001K%'] as $scope) {
            self::assertSame([], $query->page(new BillingRecordCriteria($scope)), $scope);
            self::assertSame(0, $query->count(new BillingRecordCriteria($scope)), $scope);
            self::assertNull($query->findByPosition($scope, 1), $scope);
            self::assertNull($query->findByInvoice($scope, 'FAC-001'), $scope);
            self::assertSame([], $query->findLatestByInvoices($scope, ['FAC-001']), $scope);
        }
    }

    public function testFindByPosition(): void
    {
        $hashes = $this->rqSeed();
        $query  = $this->rqQuery();

        $view = $query->findByPosition(' 89890001k', 2);
        self::assertInstanceOf(BillingRecordView::class, $view);
        self::assertSame([self::RQ_ISSUER, 2, 'FAC-002', $hashes[2], $hashes[1]], [$view->issuerNif, $view->position, $view->invoiceSeriesNumber, $view->hash, $view->previousHash]);
        self::assertSame([StoredBillingRecord::STATUS_ACCEPTED, 'CSV-2'], [$view->submissionStatus, $view->submissionReference]);
        self::assertSame([RecordType::Alta, '03-02-2026', 'F1', '2.10', '12.10', '2026-02-03T10:00:00+01:00'], [$view->recordType, $view->issueDate, $view->invoiceType, $view->totalTaxAmount, $view->totalAmount, $view->generatedAt]);
        self::assertSame(['Query Test Issuer', AeatEnvironment::Sandbox], [$view->issuerName, $view->environment]);
        self::assertSame(0, $view->submissionAttempts);
        self::assertNull($view->submissionErrorCode);
        self::assertNull($view->submissionError);

        self::assertNull($query->findByPosition(self::RQ_ISSUER, 99));
        self::assertNull($query->findByPosition(self::RQ_OTHER_ISSUER, 2));
    }

    public function testFindByInvoiceReturnsTheLatestRecordOfThatExactInvoice(): void
    {
        $hashes = $this->rqSeed();
        $query  = $this->rqQuery();

        $cancelled = $query->findByInvoice(self::RQ_ISSUER, 'FAC-001');
        self::assertSame([3, RecordType::Anulacion, $hashes[3]], [$cancelled?->position, $cancelled?->recordType, $cancelled?->hash]);
        self::assertSame(4, $query->findByInvoice(self::RQ_ISSUER, 'fac-001')?->position);
        self::assertSame(1, $query->findByInvoice(self::RQ_OTHER_ISSUER, 'FAC-001')?->position);
        self::assertSame(3, $query->findByInvoice(self::RQ_ISSUER, ' FAC-001 ')?->position);
        self::assertNull($query->findByInvoice(self::RQ_ISSUER, 'FAC-001 x'));
        self::assertNull($query->findByInvoice(self::RQ_ISSUER, 'FAC-404'));
    }

    public function testFindLatestByInvoicesKeepsTheGivenOrder(): void
    {
        $hashes = $this->rqSeed();
        $query  = $this->rqQuery();

        $views = $query->findLatestByInvoices(self::RQ_ISSUER, ['FAC-002', 'FAC-001', 'FAC-404', 'FAC-001', 'fac-001', ' R-001 ', 'FAC-002 ']);
        self::assertSame([2, 3, 4, 5], $this->rqPositions($views));
        self::assertSame([$hashes[2], $hashes[3], $hashes[4], $hashes[5]], array_map(static fn (BillingRecordView $view): string => $view->hash, $views));
        self::assertSame([], $query->findLatestByInvoices(self::RQ_ISSUER, []));
        self::assertSame([], $query->findLatestByInvoices(self::RQ_OTHER_ISSUER, ['FAC-002']));
    }

    public function testInvalidPagingIsRefused(): void
    {
        $query = $this->rqQuery();
        foreach ([[-1, 10], [0, 0], [0, BillingRecordQueryInterface::MAX_LIMIT + 1]] as [$offset, $limit]) {
            try {
                $query->page(new BillingRecordCriteria(self::RQ_ISSUER), $offset, $limit);
                self::fail('Expected InvalidArgumentException for ' . $offset . ' / ' . $limit);
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString((string) ($offset < 0 ? $offset : $limit), $exception->getMessage());
            }
        }
        self::assertSame([], $query->page(new BillingRecordCriteria(self::RQ_ISSUER), 0, BillingRecordQueryInterface::MAX_LIMIT));
    }

    /**
     * Appends the seeded chains; returns the hashes of ISSUER by position.
     *
     * @return array<int, string>
     */
    private function rqSeed(): array
    {
        $store = $this->rqStore();
        /** @var array<int, string> $hashes */
        $hashes = [];
        $rows   = [
            [RecordType::Alta, 'FAC-001', '15-01-2026', 'F1'],
            [RecordType::Alta, 'FAC-002', '03-02-2026', 'F1'],
            [RecordType::Anulacion, 'FAC-001', '15-01-2026', 'F1'],
            [RecordType::Alta, 'fac-001', '20-12-2025', 'F2'],
            [RecordType::Alta, 'R-001', '01-03-2026', 'R1'],
        ];
        $previous = '';
        foreach ($rows as $index => [$type, $invoice, $issueDate, $invoiceType]) {
            $position          = $index + 1;
            $hashes[$position] = strtoupper(hash('sha256', 'record-' . $position));
            self::assertSame($position, $store->append($this->rqRecord(self::RQ_ISSUER, $type, $invoice, $issueDate, $invoiceType, $previous, $hashes[$position])));
            $previous = $hashes[$position];
        }
        $store->markSubmitted(self::RQ_ISSUER, $hashes[2], true, 'CSV-2');
        $store->markSubmitted(self::RQ_ISSUER, $hashes[5], false);

        $store->append($this->rqRecord(self::RQ_OTHER_ISSUER, RecordType::Alta, 'FAC-001', '15-01-2026', 'F1', '', strtoupper(hash('sha256', 'other-1'))));

        return $hashes;
    }

    private function rqRecord(string $issuer, RecordType $type, string $invoice, string $issueDate, string $invoiceType, string $previousHash, string $hash): BillingRecord
    {
        [$day, $month, $year] = explode('-', $issueDate);

        return new BillingRecord(
            $type,
            $issuer,
            $invoice,
            $issueDate,
            $invoiceType,
            '2.10',
            '12.10',
            $year . '-' . $month . '-' . $day . 'T10:00:00+01:00',
            previousHash: $previousHash,
            hash: $hash,
            xml: '<RegistroAlta/>',
            issuerName: 'Query Test Issuer',
            environment: AeatEnvironment::Sandbox,
        );
    }

    /**
     * @param list<BillingRecordView> $views
     *
     * @return list<int>
     */
    private function rqPositions(array $views): array
    {
        return array_map(static fn (BillingRecordView $view): int => $view->position, $views);
    }
}
