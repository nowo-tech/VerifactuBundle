<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Service;

use Nowo\VerifactuBundle\Event\AfterBillingRecordGeneratedEvent;
use Nowo\VerifactuBundle\Event\BeforeBillingRecordGenerationEvent;
use Nowo\VerifactuBundle\Event\VerifactuEvents;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\IssuerIdentity;
use Nowo\VerifactuBundle\Model\StoredBillingRecord;
use Nowo\VerifactuBundle\Provider\IssuerProviderInterface;
use Nowo\VerifactuBundle\Repository\InMemoryBillingRecordStore;
use Nowo\VerifactuBundle\Repository\InMemoryHashChainRepository;
use Nowo\VerifactuBundle\Tests\Support\RecordingLockableHashChainRepository;
use Nowo\VerifactuBundle\Tests\Support\TestProcessorFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * BillingRecordProcessor::processBatch(): several records of one issuer chained in order in one lock, all or nothing.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class BillingRecordProcessorBatchTest extends TestCase
{
    public function testRecordsAreChainedInOrderInOneLock(): void
    {
        $heads      = new RecordingLockableHashChainRepository();
        $store      = new InMemoryBillingRecordStore();
        $dispatcher = new EventDispatcher();
        $generated  = [];
        $dispatcher->addListener(VerifactuEvents::AFTER_BILLING_RECORD_GENERATED, static function (AfterBillingRecordGeneratedEvent $event) use (&$generated): void {
            $generated[] = $event->getRecord()->invoiceSeriesNumber;
        });
        $processor = TestProcessorFactory::create($heads, $store, $dispatcher);
        self::assertSame([], $processor->process(TestProcessorFactory::record('F-0'))['errors']);
        $head = $heads->getLastState('89890001K')?->hash;

        $result = $processor->processBatch([TestProcessorFactory::record('F-1'), TestProcessorFactory::record(''), TestProcessorFactory::record('F-3')]);
        self::assertSame([1 => ['validation.invoice_series_number.empty']], $result['errors'], 'Validation runs before the lock');
        self::assertSame(['lock:89890001K', 'unlock:89890001K'], $this->locks($heads), 'Only the seed record took the lock');

        $result = $processor->processBatch([TestProcessorFactory::record('F-1'), TestProcessorFactory::record('F-2'), TestProcessorFactory::record('F-3')]);

        self::assertSame([], $result['errors']);
        [$first, $second, $third] = $result['records'];
        self::assertSame([$head, $first->hash, $second->hash], [$first->previousHash, $second->previousHash, $third->previousHash]);
        self::assertSame($third->hash, $heads->getLastState('89890001K')?->hash);
        self::assertSame(['lock:89890001K', 'unlock:89890001K', 'lock:89890001K', 'unlock:89890001K'], $this->locks($heads), 'One lock for the whole batch');
        $stored = iterator_to_array($store->findByIssuer('89890001K'));
        self::assertSame(['F-0', 'F-1', 'F-2', 'F-3'], array_map(static fn (StoredBillingRecord $s): string => $s->record->invoiceSeriesNumber, $stored));
        self::assertSame(['F-0', 'F-1', 'F-2', 'F-3'], $generated);
        self::assertLessThanOrEqual($second->generatedAt, $first->generatedAt);
    }

    public function testAFailureInsideTheLockChainsNothing(): void
    {
        $heads     = new InMemoryHashChainRepository();
        $store     = new InMemoryBillingRecordStore();
        $processor = TestProcessorFactory::create($heads, $store);
        self::assertSame([], $processor->process(TestProcessorFactory::record('F-0'))['errors']);
        $head = $heads->getLastState('89890001K');

        // An issuer name longer than the XSD allows passes the business rules but fails the XSD inside the lock.
        $tooLong = new BillingRecord(...[...$this->arguments(TestProcessorFactory::record('F-2')), 'issuerName' => str_repeat('N', 200)]);
        $result  = $processor->processBatch([TestProcessorFactory::record('F-1'), $tooLong, TestProcessorFactory::record('F-3')]);

        self::assertSame([1], array_keys($result['errors']));
        self::assertSame([], $result['records']);
        self::assertSame($head, $heads->getLastState('89890001K'), 'The head never points at a record that was not stored');
        self::assertCount(1, iterator_to_array($store->findByIssuer('89890001K')));
    }

    public function testOneIssuerPerBatchAndVetoesAreErrors(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(VerifactuEvents::BEFORE_BILLING_RECORD_GENERATION, static function (BeforeBillingRecordGenerationEvent $event): void {
            if ($event->getRecord()->invoiceSeriesNumber === 'VETO') {
                throw new RuntimeException('vetoed');
            }
        });
        $processor = TestProcessorFactory::create(new InMemoryHashChainRepository(), null, $dispatcher);

        $result = $processor->processBatch([TestProcessorFactory::record('F-1'), TestProcessorFactory::record('F-2', '12345678Z')]);
        self::assertSame([1], array_keys($result['errors']));

        self::assertSame(['records' => [], 'errors' => []], $processor->processBatch([]));

        $this->expectException(RuntimeException::class);
        $processor->processBatch([TestProcessorFactory::record('VETO')]);
    }

    public function testTheIssuerProviderFillsAndGuardsTheBatchAndListenerFailuresAreReturned(): void
    {
        $issuer = new class implements IssuerProviderInterface {
            public function getIssuer(): IssuerIdentity
            {
                return new IssuerIdentity('89890001K', 'Provider Issuer');
            }
        };
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(VerifactuEvents::AFTER_BILLING_RECORD_GENERATED, static function (): never {
            throw new RuntimeException('listener down');
        });
        $processor = TestProcessorFactory::create(new InMemoryHashChainRepository(), null, $dispatcher, null, $issuer);

        $refused = $processor->processBatch([TestProcessorFactory::record('F-1', '12345678Z')]);
        self::assertSame([0 => ['validation.issuer_nif.provider_mismatch']], $refused['errors']);

        $result = $processor->processBatch([TestProcessorFactory::record('F-1', ''), TestProcessorFactory::record('F-2', '')]);
        self::assertSame([], $result['errors']);
        self::assertSame(['89890001K', '89890001K'], array_map(static fn (BillingRecord $r): string => $r->issuerNif, $result['records']));
        self::assertSame('Provider Issuer', $result['records'][0]->issuerName);
        self::assertCount(2, $result['event_errors'] ?? []);
    }

    public function testADryRunChainsWithoutStoring(): void
    {
        $heads     = new InMemoryHashChainRepository();
        $processor = TestProcessorFactory::create($heads);

        $result = $processor->processBatch([TestProcessorFactory::record('F-1'), TestProcessorFactory::record('F-2')], false);

        self::assertSame($result['records'][0]->hash, $result['records'][1]->previousHash);
        self::assertNull($heads->getLastState('89890001K'));
    }

    /**
     * @return list<string>
     */
    private function locks(RecordingLockableHashChainRepository $heads): array
    {
        return array_values(array_filter($heads->calls, static fn (string $call): bool => str_contains($call, 'lock:')));
    }

    /**
     * @return array<string, mixed>
     */
    private function arguments(BillingRecord $record): array
    {
        return [
            'recordType'           => $record->recordType,
            'issuerNif'            => $record->issuerNif,
            'invoiceSeriesNumber'  => $record->invoiceSeriesNumber,
            'issueDate'            => $record->issueDate,
            'invoiceType'          => $record->invoiceType,
            'totalTaxAmount'       => $record->totalTaxAmount,
            'totalAmount'          => $record->totalAmount,
            'generatedAt'          => $record->generatedAt,
            'operationDescription' => $record->operationDescription,
        ];
    }
}
