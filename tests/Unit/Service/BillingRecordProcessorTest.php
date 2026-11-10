<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Service;

use ArrayObject;
use Nowo\VerifactuBundle\Certificate\CertificateLoader;
use Nowo\VerifactuBundle\Client\AeatSubmissionClientInterface;
use Nowo\VerifactuBundle\Client\NullAeatSubmissionClient;
use Nowo\VerifactuBundle\Event\AfterAeatSubmissionEvent;
use Nowo\VerifactuBundle\Event\AfterBillingRecordGeneratedEvent;
use Nowo\VerifactuBundle\Event\BeforeAeatSubmissionEvent;
use Nowo\VerifactuBundle\Event\BeforeBillingRecordGenerationEvent;
use Nowo\VerifactuBundle\Event\VerifactuEvents;
use Nowo\VerifactuBundle\Generator\BillingRecordXmlGenerator;
use Nowo\VerifactuBundle\Generator\HashChainGenerator;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Repository\HashChainRepositoryInterface;
use Nowo\VerifactuBundle\Repository\InMemoryHashChainRepository;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Signer\BillingRecordSignerInterface;
use Nowo\VerifactuBundle\Signer\XadesBillingRecordSigner;
use Nowo\VerifactuBundle\Tests\Support\FailingXsdValidator;
use Nowo\VerifactuBundle\Tests\Support\RecordingAeatSubmissionClient;
use Nowo\VerifactuBundle\Tests\Support\RecordingLockableHashChainRepository;
use Nowo\VerifactuBundle\Tests\Unit\Helper\TranslationHelper;
use Nowo\VerifactuBundle\Validator\AeatBusinessRulesValidator;
use Nowo\VerifactuBundle\Validator\SpanishTaxIdValidator;
use Nowo\VerifactuBundle\Validator\XsdValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\Translation\TranslatorInterface;

use function dirname;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class BillingRecordProcessorTest extends TestCase
{
    private BillingRecordProcessor $processor;

    private InMemoryHashChainRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new InMemoryHashChainRepository();
        $translator       = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(TranslationHelper::createTranslatorCallback());

        $this->processor = new BillingRecordProcessor(
            new AeatBusinessRulesValidator(new SpanishTaxIdValidator(), $translator),
            new HashChainGenerator(),
            new BillingRecordXmlGenerator(),
            new XsdValidator($translator, true),
            $this->repository,
            new NullAeatSubmissionClient(),
            new EventDispatcher(),
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
        );
    }

    public function testProcessGeneratesHashXmlAndStoresChain(): void
    {
        $record = new BillingRecord(
            RecordType::Alta,
            '89890001K',
            'FAC-2026-100',
            '09-07-2026',
            'F1',
            '21.00',
            '121.00',
            '2026-07-09T16:00:00+02:00',
            issuerName: 'Test Issuer',
            operationDescription: 'Consulting',
        );

        $result = $this->processor->process($record);

        self::assertSame([], $result['errors']);
        self::assertNotNull($result['record']->hash);
        self::assertNotNull($result['record']->xml);
        self::assertStringContainsString('<RegistroAlta', $result['record']->xml);

        $state = $this->repository->getLastState('89890001K');
        self::assertNotNull($state);
        self::assertSame($result['record']->hash, $state->hash);
    }

    public function testProcessReturnsValidationErrorsForInvalidRecord(): void
    {
        $record = new BillingRecord(
            RecordType::Alta,
            'INVALID',
            '',
            'bad-date',
            '',
            'x',
            'y',
            '',
        );

        $result = $this->processor->process($record);

        self::assertNotSame([], $result['errors']);
    }

    public function testProcessSubmitsToAeatWhenRequested(): void
    {
        $record = new BillingRecord(
            RecordType::Alta,
            '89890001K',
            'FAC-2026-101',
            '09-07-2026',
            'F1',
            '21.00',
            '121.00',
            '2026-07-09T16:00:00+02:00',
            issuerName: 'Test Issuer',
            operationDescription: 'Consulting',
        );

        $result = $this->processor->process($record, submitToAeat: true);

        self::assertSame([], $result['errors']);
        self::assertArrayHasKey('submission', $result);
        self::assertTrue($result['submission']['success']);
    }

    public function testAfterGenerationEventIsDispatched(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatched = false;
        $dispatcher->addListener(
            VerifactuEvents::AFTER_BILLING_RECORD_GENERATED,
            static function (AfterBillingRecordGeneratedEvent $event) use (&$dispatched): void {
                $dispatched = true;
                self::assertNotNull($event->getRecord()->hash);
            },
        );

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(TranslationHelper::createTranslatorCallback());

        $processor = new BillingRecordProcessor(
            new AeatBusinessRulesValidator(new SpanishTaxIdValidator(), $translator),
            new HashChainGenerator(),
            new BillingRecordXmlGenerator(),
            new XsdValidator($this->createMock(TranslatorInterface::class), false),
            $this->repository,
            new NullAeatSubmissionClient(),
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
        );

        $processor->process(new BillingRecord(
            RecordType::Alta,
            '89890001K',
            'FAC-2026-102',
            '09-07-2026',
            'F1',
            '21.00',
            '121.00',
            '2026-07-09T16:00:00+02:00',
            issuerName: 'Test Issuer',
        ));

        self::assertTrue($dispatched);
    }

    public function testProcessReturnsErrorWhenSigningIsRequiredButSignerIsMissing(): void
    {
        $processor = $this->createProcessor(mode: 'no_verifactu', signRecords: false, recordSigner: null);

        $result = $processor->process($this->createValidRecord('FAC-2026-200'));

        self::assertNotSame([], $result['errors']);
        self::assertStringContainsString('signer service is configured', $result['errors'][0]);
    }

    public function testProcessSignsRecordInNoVerifactuMode(): void
    {
        $signer = new XadesBillingRecordSigner(
            new CertificateLoader(),
            dirname(__DIR__, 2) . '/Fixtures/certs/test.p12',
            'test',
        );

        $processor = $this->createProcessor(mode: 'no_verifactu', signRecords: false, recordSigner: $signer);
        $result    = $processor->process($this->createValidRecord('FAC-2026-201'));

        self::assertSame([], $result['errors']);
        self::assertNotNull($result['record']->signedXml);
        self::assertStringContainsString('Signature', $result['record']->signedXml);
    }

    public function testProcessReturnsErrorWhenSigningIsEnabledWithoutSigner(): void
    {
        $processor = $this->createProcessor(signRecords: true, recordSigner: null);

        $result = $processor->process($this->createValidRecord('FAC-2026-204'));

        self::assertNotSame([], $result['errors']);
        self::assertStringContainsString('signer service is configured', $result['errors'][0]);
    }

    public function testBeforeGenerationEventCanMutateRecord(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(
            VerifactuEvents::BEFORE_BILLING_RECORD_GENERATION,
            static function (BeforeBillingRecordGenerationEvent $event): void {
                $record = $event->getRecord();
                $event->setRecord(new BillingRecord(
                    $record->recordType,
                    $record->issuerNif,
                    $record->invoiceSeriesNumber,
                    $record->issueDate,
                    $record->invoiceType,
                    $record->totalTaxAmount,
                    $record->totalAmount,
                    $record->generatedAt,
                    operationDescription: 'Mutated description',
                    issuerName: $record->issuerName,
                ));
            },
        );

        $processor = $this->createProcessor(eventDispatcher: $dispatcher);
        $result    = $processor->process($this->createValidRecord('FAC-2026-203'));

        self::assertSame([], $result['errors']);
        self::assertSame('Mutated description', $result['record']->operationDescription);
    }

    public function testProcessReturnsXsdValidationErrors(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(TranslationHelper::createTranslatorCallback());

        $processor = new BillingRecordProcessor(
            new AeatBusinessRulesValidator(new SpanishTaxIdValidator(), $translator),
            new HashChainGenerator(),
            new BillingRecordXmlGenerator(),
            new FailingXsdValidator($translator, true),
            $this->repository,
            new NullAeatSubmissionClient(),
            new EventDispatcher(),
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
        );

        $result = $processor->process($this->createValidRecord('FAC-2026-205'));

        self::assertSame(['XSD broken'], $result['errors']);
        self::assertNotNull($result['record']->xml);
    }

    public function testProcessWithoutPersistingLeavesHashChainUntouched(): void
    {
        $this->processor->process($this->createValidRecord('FAC-2026-300'));
        $head = $this->repository->getLastState('89890001K');
        self::assertNotNull($head);

        $result = $this->processor->process($this->createValidRecord('FAC-2026-301'), persistChainState: false);

        self::assertSame([], $result['errors']);
        self::assertSame($head->hash, $result['record']->previousHash);
        self::assertEquals($head, $this->repository->getLastState('89890001K'));
    }

    public function testProcessRunsChainReadAndWriteInsideRepositoryLock(): void
    {
        $repository = new RecordingLockableHashChainRepository();
        $processor  = $this->createProcessor(repository: $repository);

        $result = $processor->process($this->createValidRecord('FAC-2026-302'));

        self::assertSame([], $result['errors']);
        self::assertSame(['lock:89890001K', 'get', 'store', 'unlock:89890001K'], $repository->calls);
    }

    public function testProcessDoesNotLockWhenNotPersisting(): void
    {
        $repository = new RecordingLockableHashChainRepository();
        $processor  = $this->createProcessor(repository: $repository);

        $processor->process($this->createValidRecord('FAC-2026-303'), persistChainState: false);

        self::assertSame(['get'], $repository->calls);
    }

    public function testAeatSubmissionEventsAreDispatchedAroundTheSubmission(): void
    {
        $log        = new ArrayObject();
        $client     = new RecordingAeatSubmissionClient($log, ['success' => false, 'errors' => ['AEAT registration status "Incorrecto".']]);
        $dispatcher = new EventDispatcher();
        $before     = null;
        $after      = null;
        $dispatcher->addListener(
            VerifactuEvents::BEFORE_AEAT_SUBMISSION,
            static function (BeforeAeatSubmissionEvent $event) use ($log, &$before): void {
                $log->append('before');
                $before = $event;
            },
        );
        $dispatcher->addListener(
            VerifactuEvents::AFTER_AEAT_SUBMISSION,
            static function (AfterAeatSubmissionEvent $event) use ($log, &$after): void {
                $log->append('after');
                $after = $event;
            },
        );

        $result = $this->createProcessor(eventDispatcher: $dispatcher, submissionClient: $client)
            ->process($this->createValidRecord('FAC-2026-401'), submitToAeat: true);

        self::assertSame(['before', 'submit', 'after'], $log->getArrayCopy());
        self::assertInstanceOf(BeforeAeatSubmissionEvent::class, $before);
        self::assertInstanceOf(AfterAeatSubmissionEvent::class, $after);
        self::assertSame($result['record']->hash, $before->getRecord()->hash);
        self::assertSame($result['record']->xml, $after->getRecord()->xml);
        self::assertArrayHasKey('submission', $result);
        self::assertSame($result['submission'], $after->getSubmission());
        self::assertFalse($after->isSuccessful());
    }

    public function testAeatSubmissionListenersCannotChangeWhatIsSentOrReturned(): void
    {
        $log        = new ArrayObject();
        $client     = new RecordingAeatSubmissionClient($log);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(
            VerifactuEvents::BEFORE_AEAT_SUBMISSION,
            static function (BeforeAeatSubmissionEvent $event): void {
                $event->getRecord()->xml       = '<tampered/>';
                $event->getRecord()->signedXml = '<tampered/>';
                $event->getRecord()->hash      = 'tampered';
            },
        );
        $dispatcher->addListener(
            VerifactuEvents::AFTER_AEAT_SUBMISSION,
            static function (AfterAeatSubmissionEvent $event): void {
                $event->getRecord()->hash = 'tampered';
            },
        );

        $result = $this->createProcessor(eventDispatcher: $dispatcher, submissionClient: $client)
            ->process($this->createValidRecord('FAC-2026-402'), submitToAeat: true);

        self::assertCount(1, $client->sent);
        self::assertNotNull($result['record']->xml);
        self::assertSame($result['record']->xml, $client->sent[0]['xml']);
        self::assertNull($client->sent[0]['signedXml']);
        self::assertNotSame('tampered', $client->sent[0]['hash']);
        self::assertNotSame('tampered', $result['record']->hash);
        self::assertSame($result['record']->hash, $this->repository->getLastState('89890001K')?->hash);
        self::assertSame(['success' => true, 'reference' => 'CSV-1'], $result['submission'] ?? null);
    }

    public function testAeatSubmissionEventsAreNotDispatchedWithoutSubmission(): void
    {
        $log        = new ArrayObject();
        $client     = new RecordingAeatSubmissionClient($log);
        $dispatcher = new EventDispatcher();
        $listener   = static function () use ($log): void {
            $log->append('event');
        };
        $dispatcher->addListener(VerifactuEvents::BEFORE_AEAT_SUBMISSION, $listener);
        $dispatcher->addListener(VerifactuEvents::AFTER_AEAT_SUBMISSION, $listener);

        $processor = $this->createProcessor(eventDispatcher: $dispatcher, submissionClient: $client);
        $processor->process($this->createValidRecord('FAC-2026-403'));
        $processor->process($this->createValidRecord(''), submitToAeat: true);

        self::assertSame([], $log->getArrayCopy());
    }

    private function createValidRecord(string $seriesNumber): BillingRecord
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

    private function createProcessor(
        string $mode = 'verifactu',
        bool $signRecords = false,
        ?BillingRecordSignerInterface $recordSigner = null,
        ?EventDispatcher $eventDispatcher = null,
        ?HashChainRepositoryInterface $repository = null,
        ?AeatSubmissionClientInterface $submissionClient = null,
    ): BillingRecordProcessor {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(TranslationHelper::createTranslatorCallback());

        return new BillingRecordProcessor(
            new AeatBusinessRulesValidator(new SpanishTaxIdValidator(), $translator),
            new HashChainGenerator(),
            new BillingRecordXmlGenerator(),
            new XsdValidator($translator, true),
            $repository ?? $this->repository,
            $submissionClient ?? new NullAeatSubmissionClient(),
            $eventDispatcher ?? new EventDispatcher(),
            ['nif' => '89890001K', 'name' => 'Test Issuer'],
            [
                'manufacturer_nif'  => '89890001K',
                'manufacturer_name' => 'Nowo.tech',
                'name'              => 'VerifactuBundle',
                'id'                => '01',
                'version'           => '1.0.0',
            ],
            ['number' => '001'],
            $mode,
            $signRecords,
            $recordSigner,
        );
    }
}
