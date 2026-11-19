<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Service;

use ArrayObject;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Nowo\VerifactuBundle\Event\VerifactuEvents;
use Nowo\VerifactuBundle\Exception\ChainTransactionLostException;
use Nowo\VerifactuBundle\Exception\SubmittedRecordNotCommittedException;
use Nowo\VerifactuBundle\Generator\BillingRecordXmlGenerator;
use Nowo\VerifactuBundle\Generator\HashChainGenerator;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Repository\BillingRecordStoreInterface;
use Nowo\VerifactuBundle\Repository\HashChainRepositoryInterface;
use Nowo\VerifactuBundle\Repository\InMemoryHashChainRepository;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Tests\Support\RecordingAeatSubmissionClient;
use Nowo\VerifactuBundle\Tests\Support\RecordingLogger;
use Nowo\VerifactuBundle\Tests\Support\TransactionStateHashChainRepository;
use Nowo\VerifactuBundle\Tests\Unit\Helper\TranslationHelper;
use Nowo\VerifactuBundle\Validator\AeatBusinessRulesValidator;
use Nowo\VerifactuBundle\Validator\SpanishTaxIdValidator;
use Nowo\VerifactuBundle\Validator\XsdValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use const JSON_THROW_ON_ERROR;

/**
 * REQ-RUNTIME-003 rule 8: before the AEAT submission, a database failure raised by a listener inside an open
 * transaction is rethrown (the chain head is not committed; containing it would submit or return a record whose head
 * never commits). Every other listener failure stays contained in `event_errors`, as before.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class BillingRecordProcessorListenerDatabaseFailureTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function beforeSubmissionEvents(): iterable
    {
        yield 'after generated' => [VerifactuEvents::AFTER_BILLING_RECORD_GENERATED];
        yield 'before AEAT submission' => [VerifactuEvents::BEFORE_AEAT_SUBMISSION];
    }

    #[DataProvider('beforeSubmissionEvents')]
    public function testDatabaseFailureInsideATransactionIsRethrownAndNothingIsSubmitted(string $event): void
    {
        $failure    = self::deadlock();
        $log        = new ArrayObject();
        $client     = new RecordingAeatSubmissionClient($log);
        $logger     = new RecordingLogger();
        $repository = new TransactionStateHashChainRepository(true);
        $processor  = $this->processor($this->throwingOn($event, $failure), $repository, $client, $logger);

        $thrown = null;
        try {
            $processor->process($this->record('FAC-2026-701'), submitToAeat: true);
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        self::assertSame($failure, $thrown);
        self::assertSame([], $client->sent, 'nothing reaches the AEAT');
        self::assertStringContainsString('rethrown', $logger->records[0]['message'] ?? '');
        self::assertStringNotContainsString('89890001K', json_encode($logger->records, JSON_THROW_ON_ERROR));
    }

    public function testWrappedDatabaseFailureIsFoundThroughPrevious(): void
    {
        $failure   = new RuntimeException('listener wrapper', 0, self::deadlock());
        $processor = $this->processor($this->throwingOn(VerifactuEvents::AFTER_BILLING_RECORD_GENERATED, $failure), new TransactionStateHashChainRepository(true));

        $this->expectExceptionObject($failure);
        $processor->process($this->record('FAC-2026-702'));
    }

    public function testDatabaseFailureAfterTheSubmissionInsideATransactionCarriesTheRecordAndTheAnswer(): void
    {
        $failure   = self::deadlock();
        $client    = new RecordingAeatSubmissionClient(new ArrayObject());
        $logger    = new RecordingLogger();
        $processor = $this->processor($this->throwingOn(VerifactuEvents::AFTER_AEAT_SUBMISSION, $failure), new TransactionStateHashChainRepository(true), $client, $logger);

        try {
            $processor->process($this->record('FAC-2026-703'), submitToAeat: true);
            self::fail('SubmittedRecordNotCommittedException expected');
        } catch (SubmittedRecordNotCommittedException $exception) {
            // The record is at the AEAT: its answer and the exact record are never lost.
            self::assertSame(['success' => true, 'reference' => 'CSV-1'], $exception->submission);
            self::assertSame($client->sent[0]['hash'], $exception->record->hash);
            self::assertSame($failure, $exception->getPrevious());
        }
        self::assertSame(['warning', 'error', 'critical'], array_column($logger->records, 'level'));
        self::assertStringNotContainsString('89890001K', json_encode($logger->records, JSON_THROW_ON_ERROR));
    }

    public function testStoreDatabaseFailureAfterTheSubmissionInsideATransactionCarriesTheRecordAndTheAnswer(): void
    {
        $failure = self::deadlock();
        $store   = $this->createMock(BillingRecordStoreInterface::class);
        $store->method('markSubmitted')->willThrowException($failure);
        $processor = $this->processor(new EventDispatcher(), new TransactionStateHashChainRepository(true), recordStore: $store);

        try {
            $processor->process($this->record('FAC-2026-709'), submitToAeat: true);
            self::fail('SubmittedRecordNotCommittedException expected');
        } catch (SubmittedRecordNotCommittedException $exception) {
            self::assertSame('CSV-1', $exception->submission['reference'] ?? null);
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    public function testOtherFailuresAfterTheSubmissionInsideATransactionStayContained(): void
    {
        $storeFailure = new RuntimeException('store, not a database error');
        $store        = $this->createMock(BillingRecordStoreInterface::class);
        $store->method('markSubmitted')->willThrowException($storeFailure);
        $listenerFailure = new RuntimeException('listener, not a database error');
        $repository      = new TransactionStateHashChainRepository(true);
        $logger          = new RecordingLogger();
        $processor       = $this->processor($this->throwingOn(VerifactuEvents::AFTER_AEAT_SUBMISSION, $listenerFailure), $repository, logger: $logger, recordStore: $store);

        $result = $processor->process($this->record('FAC-2026-710'), submitToAeat: true);

        self::assertSame([$storeFailure], $result['store_errors'] ?? null);
        self::assertSame($listenerFailure, $result['event_errors'][0]['exception'] ?? null);
        self::assertSame(2, $repository->verifications, 'the head is checked before and after the AEAT request');
        self::assertSame('warning', $logger->records[0]['level']);
    }

    public function testHeadGoneBeforeTheSubmissionSendsNothing(): void
    {
        $client    = new RecordingAeatSubmissionClient(new ArrayObject());
        $processor = $this->processor(new EventDispatcher(), new TransactionStateHashChainRepository(true, [1]), $client);

        try {
            $processor->process($this->record('FAC-2026-711'), submitToAeat: true);
            self::fail('ChainTransactionLostException expected');
        } catch (ChainTransactionLostException) {
        }
        self::assertSame([], $client->sent);
    }

    public function testHeadGoneAfterTheSubmissionCarriesTheRecordAndTheAnswer(): void
    {
        $client    = new RecordingAeatSubmissionClient(new ArrayObject());
        $processor = $this->processor(new EventDispatcher(), new TransactionStateHashChainRepository(true, [2]), $client);

        try {
            $processor->process($this->record('FAC-2026-712'), submitToAeat: true);
            self::fail('SubmittedRecordNotCommittedException expected');
        } catch (SubmittedRecordNotCommittedException $exception) {
            self::assertInstanceOf(ChainTransactionLostException::class, $exception->getPrevious());
            self::assertSame('CSV-1', $exception->submission['reference'] ?? null);
        }
        self::assertCount(1, $client->sent);
    }

    public function testDryRunInsideATransactionNeverChecksTheHead(): void
    {
        $repository = new TransactionStateHashChainRepository(true, [1, 2]);
        $result     = $this->processor(new EventDispatcher(), $repository)->process($this->record('FAC-2026-713'), submitToAeat: true, persistChainState: false);

        self::assertSame('CSV-1', $result['submission']['reference'] ?? null);
        self::assertSame(0, $repository->verifications);
    }

    public function testDatabaseFailureOutsideATransactionStaysContained(): void
    {
        $failure    = self::deadlock();
        $repository = new TransactionStateHashChainRepository(false);
        $processor  = $this->processor($this->throwingOn(VerifactuEvents::AFTER_BILLING_RECORD_GENERATED, $failure), $repository);

        $result = $processor->process($this->record('FAC-2026-704'));

        self::assertSame([['event' => VerifactuEvents::AFTER_BILLING_RECORD_GENERATED, 'exception' => $failure]], $result['event_errors'] ?? null);
        self::assertSame($result['record']->hash, $repository->getLastState('89890001K')?->hash);
    }

    public function testDatabaseFailureWithARepositoryThatCannotTellStaysContained(): void
    {
        $failure   = self::deadlock();
        $processor = $this->processor($this->throwingOn(VerifactuEvents::AFTER_BILLING_RECORD_GENERATED, $failure), new InMemoryHashChainRepository());

        $result = $processor->process($this->record('FAC-2026-705'));

        self::assertSame($failure, $result['event_errors'][0]['exception'] ?? null);
    }

    public function testOtherFailureInsideATransactionStaysContained(): void
    {
        $failure   = new RuntimeException('not a database error');
        $processor = $this->processor($this->throwingOn(VerifactuEvents::AFTER_BILLING_RECORD_GENERATED, $failure), new TransactionStateHashChainRepository(true));

        $result = $processor->process($this->record('FAC-2026-706'));

        self::assertSame($failure, $result['event_errors'][0]['exception'] ?? null);
    }

    public function testBatchDatabaseFailureInsideATransactionIsRethrown(): void
    {
        $failure   = self::deadlock();
        $processor = $this->processor($this->throwingOn(VerifactuEvents::AFTER_BILLING_RECORD_GENERATED, $failure), new TransactionStateHashChainRepository(true));

        $this->expectExceptionObject($failure);
        $processor->processBatch([$this->record('FAC-2026-707'), $this->record('FAC-2026-708')]);
    }

    public function testPersistSubmittedRecordStoresExactlyTheRecordTheAeatReceived(): void
    {
        $record = $this->processor(new EventDispatcher(), new TransactionStateHashChainRepository(false))->process($this->record('FAC-2026-714'))['record'];
        self::assertNull($record->previousHash);

        $store = $this->createMock(BillingRecordStoreInterface::class);
        $store->expects(self::once())->method('append')->with($record);
        $store->expects(self::once())->method('markSubmitted')->with('89890001K', (string) $record->hash, true, 'CSV-1');
        $repository = new TransactionStateHashChainRepository(false);
        $processor  = $this->processor(new EventDispatcher(), $repository, recordStore: $store);

        self::assertTrue($processor->persistSubmittedRecord($record, ['success' => true, 'reference' => 'CSV-1']));
        self::assertSame($record->hash, $repository->getLastState('89890001K')?->hash);
        self::assertSame($record->environment, $repository->getLastState('89890001K')?->environment);
        // Already the head: nothing is written again.
        self::assertTrue($processor->persistSubmittedRecord($record, ['success' => true, 'reference' => 'CSV-1']));
    }

    public function testPersistSubmittedRecordRefusesWhenTheHeadMoved(): void
    {
        $record     = $this->processor(new EventDispatcher(), new TransactionStateHashChainRepository(false))->process($this->record('FAC-2026-715'))['record'];
        $repository = new InMemoryHashChainRepository();
        $repository->storeLastHash('89890001K', str_repeat('C', 64));

        self::assertFalse($this->processor(new EventDispatcher(), $repository)->persistSubmittedRecord($record, ['success' => false, 'reference' => 7]));
        self::assertSame(str_repeat('C', 64), $repository->getLastHash('89890001K'));

        $empty = new InMemoryHashChainRepository();
        self::assertTrue($this->processor(new EventDispatcher(), $empty)->persistSubmittedRecord($record, ['success' => false]));
        self::assertSame($record->hash, $empty->getLastHash('89890001K'));
    }

    private static function deadlock(): DeadlockException
    {
        return new DeadlockException(new class('Deadlock found when trying to get lock', '40001', 1213) extends AbstractException {}, null);
    }

    private function throwingOn(string $event, Throwable $failure): EventDispatcher
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener($event, static function () use ($failure): never {
            throw $failure;
        });

        return $dispatcher;
    }

    private function record(string $seriesNumber): BillingRecord
    {
        return new BillingRecord(
            RecordType::Alta,
            '89890001K',
            $seriesNumber,
            '09-07-2026',
            'F1',
            '21.00',
            '121.00',
            '2026-07-09T16:00:00+02:00',
            issuerName: 'Test Issuer',
            operationDescription: 'Consulting',
        );
    }

    private function processor(
        EventDispatcher $dispatcher,
        HashChainRepositoryInterface $repository,
        ?RecordingAeatSubmissionClient $client = null,
        ?RecordingLogger $logger = null,
        ?BillingRecordStoreInterface $recordStore = null,
    ): BillingRecordProcessor {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(TranslationHelper::createTranslatorCallback());

        return new BillingRecordProcessor(
            new AeatBusinessRulesValidator(new SpanishTaxIdValidator(), $translator),
            new HashChainGenerator(),
            new BillingRecordXmlGenerator(),
            new XsdValidator($translator, true),
            $repository,
            $client ?? new RecordingAeatSubmissionClient(new ArrayObject()),
            $dispatcher,
            ['nif' => '89890001K', 'name' => 'Test Issuer'],
            [
                'manufacturer_nif'  => '89890001K',
                'manufacturer_name' => 'Nowo.tech',
                'name'              => 'VerifactuBundle',
                'id'                => '01',
                'version'           => '1.0.0',
            ],
            ['number' => '001'],
            'verifactu',
            false,
            null,
            $logger,
            $recordStore,
        );
    }
}
