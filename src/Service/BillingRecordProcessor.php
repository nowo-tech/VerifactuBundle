<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Service;

use Nowo\VerifactuBundle\Client\AeatSubmissionClientInterface;
use Nowo\VerifactuBundle\Event\AfterAeatSubmissionEvent;
use Nowo\VerifactuBundle\Event\AfterBillingRecordGeneratedEvent;
use Nowo\VerifactuBundle\Event\BeforeAeatSubmissionEvent;
use Nowo\VerifactuBundle\Event\BeforeBillingRecordGenerationEvent;
use Nowo\VerifactuBundle\Event\VerifactuEvents;
use Nowo\VerifactuBundle\Generator\BillingRecordXmlGenerator;
use Nowo\VerifactuBundle\Generator\HashChainGenerator;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\HashChainState;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Repository\HashChainRepositoryInterface;
use Nowo\VerifactuBundle\Repository\LockableHashChainRepositoryInterface;
use Nowo\VerifactuBundle\Signer\BillingRecordSignerInterface;
use Nowo\VerifactuBundle\Validator\AeatBusinessRulesValidator;
use Nowo\VerifactuBundle\Validator\XsdValidator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * Orchestrates validation, hash chaining, XML generation, XSD validation, signing, and AEAT submission.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsAlias(id: self::SERVICE_NAME, public: true)]
final class BillingRecordProcessor
{
    public const SERVICE_NAME = 'nowo_verifactu.service.billing_record_processor';

    /**
     * Receives the generators, validators, signer, repository, submission client and bundle configuration.
     *
     * @param array<string, mixed> $issuerConfig
     * @param array<string, mixed> $softwareConfig
     * @param array<string, mixed> $installationConfig
     */
    public function __construct(
        private readonly AeatBusinessRulesValidator $validator,
        private readonly HashChainGenerator $hashChainGenerator,
        private readonly BillingRecordXmlGenerator $xmlGenerator,
        private readonly XsdValidator $xsdValidator,
        private readonly HashChainRepositoryInterface $hashChainRepository,
        private readonly AeatSubmissionClientInterface $submissionClient,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly array $issuerConfig,
        private readonly array $softwareConfig,
        private readonly array $installationConfig,
        private readonly string $mode,
        private readonly bool $signRecords,
        private readonly ?BillingRecordSignerInterface $recordSigner = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Chains, renders, validates and signs the record, stores the new chain head and optionally submits it to the AEAT.
     *
     * @param bool $persistChainState when false (dry runs), the record is chained onto the current head but
     *                                the new state is NOT stored, so the persistent hash chain is left untouched
     *
     * An exception thrown by a `BEFORE_AEAT_SUBMISSION` or `AFTER_AEAT_SUBMISSION` listener never stops the submission
     * nor hides its result: it is logged (event name and exception class only) and returned in `event_errors`
     *
     * @return array{record: BillingRecord, errors: list<string>, submission?: array<string, mixed>, event_errors?: list<array{event: string, exception: Throwable}>}
     */
    public function process(BillingRecord $record, bool $submitToAeat = false, bool $persistChainState = true): array
    {
        $beforeEvent = new BeforeBillingRecordGenerationEvent($record);
        $this->eventDispatcher->dispatch($beforeEvent, VerifactuEvents::BEFORE_BILLING_RECORD_GENERATION);
        $record = $beforeEvent->getRecord();

        $errors = $this->validator->validate($record);
        if ($errors !== []) {
            return ['record' => $record, 'errors' => $errors];
        }

        $operation = fn (): array => $this->chainRecord($record, $persistChainState);
        $chained   = $persistChainState && $this->hashChainRepository instanceof LockableHashChainRepositoryInterface
            ? $this->hashChainRepository->withChainLock($record->issuerNif, $operation)
            : $operation();

        if ($chained['errors'] !== []) {
            return $chained;
        }

        $record = $chained['record'];

        // Listeners receive a copy: they cannot change the hash, XML or signed XML that is submitted and returned.
        $this->eventDispatcher->dispatch(
            new AfterBillingRecordGeneratedEvent(clone $record),
            VerifactuEvents::AFTER_BILLING_RECORD_GENERATED,
        );

        $result = ['record' => $record, 'errors' => []];

        if ($submitToAeat) {
            // Listeners receive copies: they observe the submission but cannot change what is sent to the AEAT.
            // The chain head already points at this record, so a failing (informational) listener must neither stop
            // the submission nor hide its result: either would lose a fiscal record or fork the chain on a retry.
            $eventErrors = [];
            $this->dispatchSubmissionEvent(
                new BeforeAeatSubmissionEvent(clone $record),
                VerifactuEvents::BEFORE_AEAT_SUBMISSION,
                $eventErrors,
            );

            $result['submission'] = $this->submissionClient->submit($record);

            $this->dispatchSubmissionEvent(
                new AfterAeatSubmissionEvent(clone $record, $result['submission']),
                VerifactuEvents::AFTER_AEAT_SUBMISSION,
                $eventErrors,
            );

            if ($eventErrors !== []) {
                $result['event_errors'] = $eventErrors;
            }
        }

        return $result;
    }

    /**
     * Reads the chain head, hashes, renders, validates and signs the record, then stores the new head.
     *
     * Runs inside the repository chain lock when available (read-then-write must be atomic per issuer).
     *
     * @return array{record: BillingRecord, errors: list<string>}
     */
    private function chainRecord(BillingRecord $record, bool $persistChainState): array
    {
        $previousState = $this->hashChainRepository->getLastState($record->issuerNif);
        $record        = new BillingRecord(
            $record->recordType,
            $record->issuerNif,
            $record->invoiceSeriesNumber,
            $record->issueDate,
            $record->invoiceType,
            $record->totalTaxAmount,
            $record->totalAmount,
            $record->generatedAt,
            $record->lines,
            $record->taxBreakdown,
            $previousState?->hash,
            issuerName: $record->issuerName,
            operationDescription: $record->operationDescription,
        );

        $hash   = $this->hashChainGenerator->computeHash($record);
        $record = $record->withHash($hash, $previousState?->hash);
        $xml    = $this->xmlGenerator->generate(
            $record,
            $this->issuerConfig,
            $this->softwareConfig,
            $this->installationConfig,
            $previousState,
        );
        $record = $record->withXml($xml);

        $schemaType = $record->recordType === RecordType::Alta
            ? XsdValidator::SCHEMA_REGISTRO_ALTA
            : XsdValidator::SCHEMA_REGISTRO_ANULACION;
        $xsdErrors = $this->xsdValidator->validate($xml, $schemaType);
        if ($xsdErrors !== []) {
            return ['record' => $record, 'errors' => $xsdErrors];
        }

        if ($this->shouldSign()) {
            if (!$this->recordSigner instanceof BillingRecordSignerInterface) {
                return ['record' => $record, 'errors' => ['XAdES signing is required but no signer service is configured.']];
            }

            $record = $record->withSignedXml($this->recordSigner->sign($xml));
        }

        if ($persistChainState) {
            $this->hashChainRepository->storeLastState(new HashChainState(
                $record->issuerNif,
                $record->invoiceSeriesNumber,
                $record->issueDate,
                $hash,
            ));
        }

        return ['record' => $record, 'errors' => []];
    }

    /**
     * Dispatches an AEAT submission event and contains any listener failure.
     *
     * @param list<array{event: string, exception: Throwable}> $eventErrors
     */
    private function dispatchSubmissionEvent(object $event, string $eventName, array &$eventErrors): void
    {
        try {
            $this->eventDispatcher->dispatch($event, $eventName);
        } catch (Throwable $exception) {
            // No record data and no exception message in the log: both may carry personal data (NIF, names).
            $this->logger?->error('A {event} listener failed ({exception_class}); the AEAT submission and its result are kept.', [
                'event'           => $eventName,
                'exception_class' => $exception::class,
            ]);
            $eventErrors[] = ['event' => $eventName, 'exception' => $exception];
        }
    }

    private function shouldSign(): bool
    {
        return $this->signRecords || $this->mode === 'no_verifactu';
    }
}
