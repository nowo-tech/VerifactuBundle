<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Repository;

use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\BillingRecordCriteria;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Model\StoredBillingRecord;
use Nowo\VerifactuBundle\Repository\BillingRecordQueryInterface;
use Nowo\VerifactuBundle\Repository\BillingRecordStoreInterface;
use Nowo\VerifactuBundle\Repository\InMemoryBillingRecordStore;
use Nowo\VerifactuBundle\Repository\StoreBackedBillingRecordQuery;
use Nowo\VerifactuBundle\Tests\Support\RecordQueryScenarios;
use PHPUnit\Framework\TestCase;

/**
 * The query that walks a record store (in-memory store, custom record_store.service), 1.10.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class StoreBackedBillingRecordQueryTest extends TestCase
{
    use RecordQueryScenarios;

    private InMemoryBillingRecordStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryBillingRecordStore();
    }

    protected function rqStore(): BillingRecordStoreInterface
    {
        return $this->store;
    }

    protected function rqQuery(): BillingRecordQueryInterface
    {
        return new StoreBackedBillingRecordQuery($this->store);
    }

    public function testViewsFromAStoreCarryNoWriteOrAnswerTime(): void
    {
        $this->rqSeed();

        $view = $this->rqQuery()->findByPosition('89890001K', 1);
        self::assertNull($view?->recordedAt);
        self::assertNull($view?->submittedAt);
    }

    public function testRecordsOfAnotherIssuerReturnedByACustomStoreAreIgnored(): void
    {
        $record = new BillingRecord(RecordType::Alta, 'B12345674', 'FAC-001', '15-01-2026', 'F1', '2.10', '12.10', '2026-01-15T10:00:00+01:00', previousHash: '', hash: 'AB');
        $store  = $this->createStub(BillingRecordStoreInterface::class);
        $store->method('findByIssuer')->willReturn([new StoredBillingRecord(1, $record)]);

        $query = new StoreBackedBillingRecordQuery($store);
        self::assertSame([], $query->page(new BillingRecordCriteria('89890001K')));
        self::assertNull($query->findByPosition('89890001K', 1));
        self::assertNull($query->findByInvoice('89890001K', 'FAC-001'));
    }
}
