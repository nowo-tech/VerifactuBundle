<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Service;

use ArrayObject;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use InvalidArgumentException;
use LogicException;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\VerifactuBundle\Event\VerifactuEvents;
use Nowo\VerifactuBundle\Exception\ChainTransactionLostException;
use Nowo\VerifactuBundle\Exception\SubmittedRecordNotCommittedException;
use Nowo\VerifactuBundle\Generator\BillingRecordXmlGenerator;
use Nowo\VerifactuBundle\Generator\HashChainGenerator;
use Nowo\VerifactuBundle\Model\AeatEnvironment;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\HashChainState;
use Nowo\VerifactuBundle\Model\IssuerIdentity;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Provider\IssuerProviderInterface;
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

use function count;

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
        self::assertSame(1, $repository->rollbackOnlyMarks, 'a host that commits anyway fails loudly');
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
            self::assertSame($failure, $exception->databaseError);
            self::assertNull($exception->headLoss);
            // Never a retryable conflict: the AEAT holds the record, a retry would send a second one (UO2-1).
            self::assertNull($exception->getPrevious());
            self::assertFalse((new TransientFailureClassifier())->isTransient($exception));
            self::assertNull(TransientFailureClassifier::findConflict($exception));
        }
        self::assertSame(['warning', 'error', 'critical'], array_column($logger->records, 'level'));
        self::assertSame(DeadlockException::class, $logger->records[2]['context']['database_error'] ?? null);
        self::assertSame('no', $logger->records[2]['context']['head_lost'] ?? null);
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
            self::assertSame($failure, $exception->databaseError);
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
            self::assertInstanceOf(ChainTransactionLostException::class, $exception->headLoss);
            self::assertNull($exception->databaseError);
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
        $store->expects(self::exactly(2))->method('markSubmitted')->with('89890001K', (string) $record->hash, true, 'CSV-1');
        $repository = new TransactionStateHashChainRepository(false);
        $processor  = $this->processor(new EventDispatcher(), $repository, recordStore: $store);

        self::assertTrue($processor->persistSubmittedRecord($record, ['success' => true, 'reference' => 'CSV-1']));
        self::assertSame($record->hash, $repository->getLastState('89890001K')?->hash);
        self::assertSame($record->environment, $repository->getLastState('89890001K')?->environment);
        // Already the head: nothing is written again.
        self::assertTrue($processor->persistSubmittedRecord($record, ['success' => true, 'reference' => 'CSV-1']));
    }

    public function testDatabaseErrorAndHeadLossAfterTheSubmissionAreBothKept(): void
    {
        $failure    = self::deadlock();
        $logger     = new RecordingLogger();
        $repository = new TransactionStateHashChainRepository(true, [2]);
        $processor  = $this->processor($this->throwingOn(VerifactuEvents::AFTER_AEAT_SUBMISSION, $failure), $repository, logger: $logger);

        try {
            $processor->process($this->record('FAC-2026-716'), submitToAeat: true);
            self::fail('SubmittedRecordNotCommittedException expected');
        } catch (SubmittedRecordNotCommittedException $exception) {
            self::assertSame($failure, $exception->databaseError);
            self::assertInstanceOf(ChainTransactionLostException::class, $exception->headLoss);
        }
        self::assertSame(1, $repository->rollbackOnlyMarks);
        $critical = $logger->records[count($logger->records) - 1];
        self::assertSame('critical', $critical['level']);
        self::assertSame('yes (other head)', $critical['context']['head_lost']);
    }

    public function testPersistSubmittedRecordRecordsTheAnswerWhenTheRecordIsAlreadyTheHead(): void
    {
        $repository = new TransactionStateHashChainRepository(false);
        $record     = $this->processor(new EventDispatcher(), $repository)->process($this->record('FAC-2026-717'))['record'];
        $store      = $this->createMock(BillingRecordStoreInterface::class);
        $store->expects(self::never())->method('append');
        $store->expects(self::once())->method('markSubmitted')->with('89890001K', (string) $record->hash, true, 'CSV-9');

        self::assertTrue($this->processor(new EventDispatcher(), $repository, recordStore: $store)->persistSubmittedRecord($record, ['success' => true, 'reference' => 'CSV-9']));
    }

    /**
     * @return iterable<string, array{callable(BillingRecord): BillingRecord, string}>
     */
    public static function forgedRecords(): iterable
    {
        yield 'forged hash' => [static fn (BillingRecord $r): BillingRecord => $r->withHash(str_repeat('A', 64), $r->previousHash), 'fingerprint'];
        yield 'no hash' => [static fn (BillingRecord $r): BillingRecord => $r->withHash('', $r->previousHash), 'fingerprint'];
        yield 'altered amount' => [static fn (BillingRecord $r): BillingRecord => (new BillingRecord(RecordType::Alta, $r->issuerNif, $r->invoiceSeriesNumber, $r->issueDate, 'F1', '21.00', '999.00', $r->generatedAt, previousHash: $r->previousHash, operationDescription: 'x', environment: $r->environment))->withHash((string) $r->hash, $r->previousHash)->withXml((string) $r->xml), 'fingerprint'];
        yield 'other XML' => [static fn (BillingRecord $r): BillingRecord => $r->withXml('<x/>'), 'XML'];
        yield 'hash only in a foreign element' => [static fn (BillingRecord $r): BillingRecord => $r->withXml('<x>' . $r->hash . '</x>'), 'XML'];
        yield 'malformed XML' => [static fn (BillingRecord $r): BillingRecord => $r->withXml('<RegistroAlta><Huella>' . $r->hash . '</Huella>'), 'XML'];
        yield 'annulment XML for an Alta' => [static fn (BillingRecord $r): BillingRecord => $r->withXml(str_replace('RegistroAlta', 'RegistroAnulacion', (string) $r->xml)), 'XML'];
        yield 'other own Huella' => [static fn (BillingRecord $r): BillingRecord => $r->withXml(str_replace('<Huella>' . $r->hash . '</Huella>', '<Huella>' . str_repeat('B', 64) . '</Huella>', (string) $r->xml)), 'XML'];
        yield 'other invoice number in the XML' => [static fn (BillingRecord $r): BillingRecord => $r->withXml(str_replace('<NumSerieFactura>' . $r->invoiceSeriesNumber . '<', '<NumSerieFactura>OTHER<', (string) $r->xml)), 'XML'];
        yield 'other issuer in the XML' => [static fn (BillingRecord $r): BillingRecord => $r->withXml(str_replace('<IDEmisorFactura>' . $r->issuerNif . '<', '<IDEmisorFactura>B12345674<', (string) $r->xml)), 'XML'];
        yield 'other issue date in the XML' => [static fn (BillingRecord $r): BillingRecord => $r->withXml(str_replace('<FechaExpedicionFactura>' . $r->issueDate . '<', '<FechaExpedicionFactura>01-01-2026<', (string) $r->xml)), 'XML'];
        yield 'no Encadenamiento' => [static fn (BillingRecord $r): BillingRecord => $r->withXml(str_replace('Encadenamiento>', 'Otro>', (string) $r->xml)), 'XML'];
        yield 'no IDFactura' => [static fn (BillingRecord $r): BillingRecord => $r->withXml(str_replace('IDFactura>', 'Otro>', (string) $r->xml)), 'XML'];
        yield 'duplicate own Huella' => [static fn (BillingRecord $r): BillingRecord => $r->withXml(str_replace('<Huella>' . $r->hash . '</Huella>', '<Huella>' . $r->hash . '</Huella><Huella>' . str_repeat('B', 64) . '</Huella>', (string) $r->xml)), 'XML'];
        yield 'duplicate IDFactura' => [static fn (BillingRecord $r): BillingRecord => $r->withXml(preg_replace('#(</IDFactura>)#', '$1<IDFactura><IDEmisorFactura>B12345674</IDEmisorFactura><NumSerieFactura>OTHER</NumSerieFactura><FechaExpedicionFactura>01-01-2026</FechaExpedicionFactura></IDFactura>', (string) $r->xml, 1) ?? ''), 'XML'];
        yield 'duplicate NumSerieFactura' => [static fn (BillingRecord $r): BillingRecord => $r->withXml(str_replace('</NumSerieFactura>', '</NumSerieFactura><NumSerieFactura>OTHER</NumSerieFactura>', (string) $r->xml)), 'XML'];
        yield 'wrong namespace' => [static fn (BillingRecord $r): BillingRecord => $r->withXml(str_replace(BillingRecordXmlGenerator::NS_SUMINISTRO, 'urn:other', (string) $r->xml)), 'XML'];
        yield 'no namespace' => [static fn (BillingRecord $r): BillingRecord => $r->withXml(str_replace(' xmlns="' . BillingRecordXmlGenerator::NS_SUMINISTRO . '"', '', (string) $r->xml)), 'XML'];
        yield 'Huella in another namespace' => [static fn (BillingRecord $r): BillingRecord => $r->withXml(str_replace('<Huella>' . $r->hash . '</Huella>', '<o:Huella xmlns:o="urn:other">' . $r->hash . '</o:Huella>', (string) $r->xml)), 'XML'];
        yield 'internal DTD entity' => [static fn (BillingRecord $r): BillingRecord => $r->withXml(str_replace(['<RegistroAlta', '<Huella>' . $r->hash . '<'], ['<!DOCTYPE RegistroAlta [<!ENTITY h "' . $r->hash . '">]><RegistroAlta', '<Huella>&h;<'], (string) $r->xml)), 'XML'];
        yield 'a previous record on a first record' => [static fn (BillingRecord $r): BillingRecord => $r->withXml(str_replace('<Encadenamiento>', '<Encadenamiento><RegistroAnterior><Huella>' . str_repeat('C', 64) . '</Huella></RegistroAnterior>', (string) $r->xml)), 'XML'];
        yield 'no XML' => [static fn (BillingRecord $r): BillingRecord => (new BillingRecord(RecordType::Alta, $r->issuerNif, $r->invoiceSeriesNumber, $r->issueDate, 'F1', $r->totalTaxAmount, $r->totalAmount, $r->generatedAt, previousHash: $r->previousHash, issuerName: $r->issuerName, operationDescription: $r->operationDescription, environment: $r->environment))->withHash((string) $r->hash, $r->previousHash), 'XML'];
    }

    /**
     * @param callable(BillingRecord): BillingRecord $forge
     */
    #[DataProvider('forgedRecords')]
    public function testPersistSubmittedRecordRefusesAForgedRecordAndWritesNothing(callable $forge, string $reason): void
    {
        $record     = $this->processor(new EventDispatcher(), new TransactionStateHashChainRepository(false))->process($this->record('FAC-2026-718'))['record'];
        $repository = new TransactionStateHashChainRepository(false);
        $store      = $this->createMock(BillingRecordStoreInterface::class);
        $store->expects(self::never())->method('append');
        $store->expects(self::never())->method('markSubmitted');

        try {
            $this->processor(new EventDispatcher(), $repository, recordStore: $store)->persistSubmittedRecord($forge($record), ['success' => true, 'reference' => 'X']);
            self::fail('InvalidArgumentException expected');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString($reason, $exception->getMessage());
        }
        self::assertNull($repository->getLastState('89890001K'));
    }

    public function testPersistSubmittedRecordRefusesAnotherEnvironmentOrIssuer(): void
    {
        $record = $this->processor(new EventDispatcher(), new TransactionStateHashChainRepository(false))->process($this->record('FAC-2026-719'))['record'];

        $production = $this->processor(new EventDispatcher(), new TransactionStateHashChainRepository(false), environment: 'production');
        try {
            $production->persistSubmittedRecord($record, []);
            self::fail('InvalidArgumentException expected');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('configured', $exception->getMessage());
        }

        $issuer = $this->createMock(IssuerProviderInterface::class);
        $issuer->method('getIssuer')->willReturn(new IssuerIdentity('B12345674', 'Other'));
        try {
            $this->processor(new EventDispatcher(), new TransactionStateHashChainRepository(false), issuerProvider: $issuer, enforceIssuer: true)->persistSubmittedRecord($record, []);
            self::fail('InvalidArgumentException expected');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('issuer', $exception->getMessage());
        }

        // A head of another environment: not a record this chain sent.
        $heads = new TransactionStateHashChainRepository(false);
        $heads->storeLastState(new HashChainState('89890001K', 'X', '09-07-2026', (string) $record->previousHash ?: str_repeat('D', 64), AeatEnvironment::Production));
        try {
            $this->processor(new EventDispatcher(), $heads)->persistSubmittedRecord($record, ['success' => true]);
            self::fail('InvalidArgumentException expected');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('head', $exception->getMessage());
        }
    }

    public function testPersistSubmittedRecordChecksThePreviousHuellaAndAnnulments(): void
    {
        $chain     = new TransactionStateHashChainRepository(false);
        $processor = $this->processor(new EventDispatcher(), $chain);
        $alta      = $processor->process($this->record('FAC-2026-720'))['record'];
        $annulment = $processor->process(new BillingRecord(RecordType::Anulacion, '89890001K', 'FAC-2026-720', '09-07-2026', 'F1', '21.00', '121.00', '2026-07-09T16:00:00+02:00'))['record'];
        self::assertSame($alta->hash, $annulment->previousHash);
        self::assertStringContainsString('<RegistroAnulacion', (string) $annulment->xml);

        $heads = new TransactionStateHashChainRepository(false);
        $heads->storeLastState(new HashChainState('89890001K', 'FAC-2026-720', '09-07-2026', (string) $alta->hash, $alta->environment));
        $altered = $annulment->withXml(str_replace('<Huella>' . $alta->hash . '</Huella>', '<Huella>' . str_repeat('E', 64) . '</Huella>', (string) $annulment->xml));
        try {
            $this->processor(new EventDispatcher(), $heads)->persistSubmittedRecord($altered, ['success' => true, 'reference' => 'X']);
            self::fail('InvalidArgumentException expected');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('XML', $exception->getMessage());
        }
        self::assertSame($alta->hash, $heads->getLastState('89890001K')?->hash);

        self::assertTrue($this->processor(new EventDispatcher(), $heads)->persistSubmittedRecord($annulment, ['success' => true, 'reference' => 'X']));
        self::assertSame($annulment->hash, $heads->getLastState('89890001K')?->hash);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidAnswers(): iterable
    {
        yield 'no success' => [['reference' => 'X']];
        yield 'success not a bool' => [['success' => 'yes', 'reference' => 'X']];
        yield 'reference not a string' => [['success' => true, 'reference' => 7]];
    }

    /**
     * @param array<string, mixed> $submission
     */
    #[DataProvider('invalidAnswers')]
    public function testPersistSubmittedRecordRefusesAnInvalidAnswer(array $submission): void
    {
        $record     = $this->processor(new EventDispatcher(), new TransactionStateHashChainRepository(false))->process($this->record('FAC-2026-721'))['record'];
        $repository = new TransactionStateHashChainRepository(false);

        try {
            $this->processor(new EventDispatcher(), $repository)->persistSubmittedRecord($record, $submission);
            self::fail('InvalidArgumentException expected');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('answer', $exception->getMessage());
        }
        self::assertNull($repository->getLastState('89890001K'));
    }

    public function testFindLooksAlongThePreviousExceptions(): void
    {
        $record   = $this->processor(new EventDispatcher(), new TransactionStateHashChainRepository(false))->process($this->record('FAC-2026-722'))['record'];
        $original = new SubmittedRecordNotCommittedException($record, ['success' => true]);

        self::assertSame($original, SubmittedRecordNotCommittedException::find($original));
        self::assertSame($original, SubmittedRecordNotCommittedException::find(new RuntimeException('no active transaction', 0, $original)));
        self::assertNull(SubmittedRecordNotCommittedException::find(new RuntimeException('other')));

        // Bounded: a cause deeper than the walk limit is not found (a cycle-safe, constant-cost walk).
        $deep = $original;
        for ($i = 0; $i < 40; ++$i) {
            $deep = new RuntimeException('wrapper ' . $i, 0, $deep);
        }
        self::assertNull(SubmittedRecordNotCommittedException::find($deep));
    }

    public function testPersistSubmittedRecordRefusesToRunInsideATransaction(): void
    {
        $record     = $this->processor(new EventDispatcher(), new TransactionStateHashChainRepository(false))->process($this->record('FAC-2026-723'))['record'];
        $repository = new TransactionStateHashChainRepository(true);

        try {
            $this->processor(new EventDispatcher(), $repository)->persistSubmittedRecord($record, ['success' => true]);
            self::fail('LogicException expected');
        } catch (LogicException $exception) {
            self::assertStringContainsString('rolled back', $exception->getMessage());
        }
        self::assertNull($repository->getLastState('89890001K'));
    }

    public function testPersistSubmittedRecordRefusesWhenTheHeadMoved(): void
    {
        $record     = $this->processor(new EventDispatcher(), new TransactionStateHashChainRepository(false))->process($this->record('FAC-2026-715'))['record'];
        $repository = new InMemoryHashChainRepository();
        $repository->storeLastHash('89890001K', str_repeat('C', 64));

        self::assertFalse($this->processor(new EventDispatcher(), $repository)->persistSubmittedRecord($record, ['success' => false, 'reference' => null]));
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
        string $environment = 'sandbox',
        ?IssuerProviderInterface $issuerProvider = null,
        bool $enforceIssuer = false,
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
            $issuerProvider,
            null,
            null,
            null,
            $environment,
            $enforceIssuer,
        );
    }
}
