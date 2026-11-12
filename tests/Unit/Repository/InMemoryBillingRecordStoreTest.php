<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Repository;

use DateTimeImmutable;
use Nowo\VerifactuBundle\Model\AeatEnvironment;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Model\StoredBillingRecord;
use Nowo\VerifactuBundle\Repository\InMemoryBillingRecordStore;
use PHPUnit\Framework\TestCase;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class InMemoryBillingRecordStoreTest extends TestCase
{
    public function testAppendsInOrderPerIssuerAndRecordsTheSubmission(): void
    {
        $store = new InMemoryBillingRecordStore();

        self::assertSame(1, $store->append($this->record('89890001k', 'F-1', 'AAA')));
        self::assertSame(2, $store->append($this->record('89890001K', 'F-2', 'BBB')));
        self::assertSame(1, $store->append($this->record('B12345678', 'F-9', 'CCC')));

        $store->markSubmitted(' 89890001K ', 'bbb', true, 'CSV-1');
        $store->markSubmitted('89890001K', 'AAA', false);
        $store->markSubmitted('UNKNOWN', 'AAA', true);

        $records = $store->findByIssuer('89890001K');
        self::assertCount(2, $records);
        self::assertSame(StoredBillingRecord::STATUS_FAILED, $records[0]->submissionStatus);
        self::assertSame(StoredBillingRecord::STATUS_ACCEPTED, $records[1]->submissionStatus);
        self::assertSame('CSV-1', $records[1]->submissionReference);
        self::assertSame(2, $records[1]->position);

        $store->clear();
        self::assertSame([], $store->findByIssuer('89890001K'));
    }

    public function testResetDropsRecordsAndClaimsLikeClear(): void
    {
        $store = new InMemoryBillingRecordStore();
        $now   = new DateTimeImmutable('2026-07-09T16:00:00+02:00');

        $store->append($this->record('89890001K', 'F-1', 'AAA', AeatEnvironment::Sandbox));
        $store->append($this->record('B12345678', 'F-9', 'CCC', AeatEnvironment::Sandbox));
        $claimed = $store->claimNext('89890001K', 10, 'token-1', $now, 300);
        self::assertCount(1, $claimed);
        self::assertTrue($store->hasSubmissionInFlight('89890001K', $now, 300));

        // `kernel.reset` / services_resetter calls reset() directly.
        $store->reset();

        self::assertSame([], $store->findByIssuer('89890001K'));
        self::assertSame([], $store->findByIssuer('B12345678'));
        self::assertFalse($store->hasSubmissionInFlight('89890001K', $now, 300));
        self::assertSame(1, $store->append($this->record('89890001K', 'F-2', 'DDD')), 'positions restart after a reset');
    }

    private function record(string $nif, string $number, string $hash, ?AeatEnvironment $environment = null): BillingRecord
    {
        return new BillingRecord(RecordType::Alta, $nif, $number, '09-07-2026', 'F1', '1.00', '2.00', '2026-07-09T16:00:00+02:00', hash: $hash, environment: $environment);
    }
}
