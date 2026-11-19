<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Service;

use ArrayObject;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Nowo\VerifactuBundle\Event\VerifactuEvents;
use Nowo\VerifactuBundle\Generator\BillingRecordXmlGenerator;
use Nowo\VerifactuBundle\Generator\HashChainGenerator;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
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

    public function testDatabaseFailureAfterTheSubmissionStaysContainedInsideATransaction(): void
    {
        $failure   = self::deadlock();
        $client    = new RecordingAeatSubmissionClient(new ArrayObject());
        $processor = $this->processor($this->throwingOn(VerifactuEvents::AFTER_AEAT_SUBMISSION, $failure), new TransactionStateHashChainRepository(true), $client);

        $result = $processor->process($this->record('FAC-2026-703'), submitToAeat: true);

        // The record is registered at the AEAT: its answer is never lost.
        self::assertSame(['success' => true, 'reference' => 'CSV-1'], $result['submission'] ?? null);
        self::assertSame([['event' => VerifactuEvents::AFTER_AEAT_SUBMISSION, 'exception' => $failure]], $result['event_errors'] ?? null);
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
        );
    }
}
