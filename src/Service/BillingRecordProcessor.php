<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Service;

use Nowo\VerifactuBundle\Client\AeatSubmissionClientInterface;
use Nowo\VerifactuBundle\Clock\GenerationTimestamp;
use Nowo\VerifactuBundle\Event\AfterAeatSubmissionEvent;
use Nowo\VerifactuBundle\Event\AfterBillingRecordGeneratedEvent;
use Nowo\VerifactuBundle\Event\BeforeAeatSubmissionEvent;
use Nowo\VerifactuBundle\Event\BeforeBillingRecordGenerationEvent;
use Nowo\VerifactuBundle\Event\VerifactuEvents;
use Nowo\VerifactuBundle\Exception\ChainEnvironmentMismatchException;
use Nowo\VerifactuBundle\Generator\BillingRecordXmlGenerator;
use Nowo\VerifactuBundle\Generator\HashChainGenerator;
use Nowo\VerifactuBundle\Model\AeatEnvironment;
use Nowo\VerifactuBundle\Model\Amount;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\BillingRecordBatchResult;
use Nowo\VerifactuBundle\Model\BillingRecordResult;
use Nowo\VerifactuBundle\Model\HashChainState;
use Nowo\VerifactuBundle\Model\IssuerIdentity;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Model\SoftwareIdentity;
use Nowo\VerifactuBundle\Provider\AeatEnvironmentProviderInterface;
use Nowo\VerifactuBundle\Provider\ConfigIssuerProvider;
use Nowo\VerifactuBundle\Provider\ConfigSoftwareIdentityProvider;
use Nowo\VerifactuBundle\Provider\IssuerProviderInterface;
use Nowo\VerifactuBundle\Provider\SoftwareIdentityProviderInterface;
use Nowo\VerifactuBundle\Repository\BillingRecordStoreInterface;
use Nowo\VerifactuBundle\Repository\HashChainRepositoryInterface;
use Nowo\VerifactuBundle\Repository\LockableHashChainRepositoryInterface;
use Nowo\VerifactuBundle\Signer\BillingRecordSignerInterface;
use Nowo\VerifactuBundle\Validator\AeatBusinessRulesValidator;
use Nowo\VerifactuBundle\Validator\XsdValidator;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function array_key_first;
use function array_values;
use function is_string;
use function ksort;

/**
 * Orchestrates validation, hash chaining, XML generation, XSD validation, signing, and AEAT submission.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsAlias(id: self::SERVICE_NAME, public: true)]
final class BillingRecordProcessor implements BillingRecordProcessorInterface
{
    public const SERVICE_NAME = 'nowo_verifactu.service.billing_record_processor';

    /**
     * Receives the generators, validators, signer, repository, submission client and bundle configuration.
     *
     * The issuer and the software identity come from the providers on every process() call (a host can keep them in
     * its database); without providers (manual construction) the static arrays are used, as before 1.5.
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
        private readonly ?BillingRecordStoreInterface $recordStore = null,
        private readonly ?IssuerProviderInterface $issuerProvider = null,
        private readonly ?SoftwareIdentityProviderInterface $softwareIdentityProvider = null,
        private readonly ?ClockInterface $clock = null,
        private readonly ?AeatEnvironmentProviderInterface $environmentProvider = null,
        private readonly string $defaultEnvironment = 'sandbox',
        private readonly bool $enforceProviderIssuer = false,
    ) {
    }

    /**
     * Chains, renders, validates and signs the record, stores the new chain head and optionally submits it to the AEAT.
     *
     * @param bool $persistChainState when false (dry runs), the record is chained onto the current head but
     *                                the new state is NOT stored (nor appended to the record store), so the persistent
     *                                hash chain is left untouched
     *
     * A record without an issuer NIF (or name) takes the NIF (or name) of the issuer provider, resolved once per call;
     * with `providers.issuer` configured, a record for another NIF is refused (validation error). A
     * record with an empty `generatedAt` is stamped with GenerationTimestamp::now() inside the chain lock (recommended:
     * the generation times then follow the chain order). The amounts are written in their canonical
     * form (Amount::canonical()), the same string in the XML, the fingerprint and the returned record.
     *
     * An exception thrown by an `AFTER_BILLING_RECORD_GENERATED`, `BEFORE_AEAT_SUBMISSION` or `AFTER_AEAT_SUBMISSION`
     * listener never stops the submission nor hides the record or its result: it is logged (event name and exception
     * class only) and returned in `event_errors`. A failure to record the submission outcome in the record store is
     * contained the same way and returned in `store_errors`.
     *
     * @return array{record: BillingRecord, errors: list<string>, submission?: array<string, mixed>, event_errors?: list<array{event: string, exception: Throwable}>, store_errors?: list<Throwable>}
     */
    public function process(BillingRecord $record, bool $submitToAeat = false, bool $persistChainState = true): array
    {
        // Resolved once per call: the XML, the returned record and the SOAP Cabecera (through issuerName) agree.
        $issuer   = $this->resolveIssuer();
        $software = $this->resolveSoftwareIdentity();
        if (trim($record->issuerNif) === '' && trim($issuer->nif) !== '') {
            $record = $this->copy($record, issuerNif: $issuer->nif);
        }
        if ($record->issuerName === null && $issuer->name !== '') {
            $record = $this->copy($record, issuerName: $issuer->name);
        }
        // With a host issuer provider, the provider is the authority: a record for another NIF (e.g. set by a user or
        // another tenant) is refused, so it is never signed nor submitted with that issuer's certificate.
        if ($this->enforceProviderIssuer && trim($issuer->nif) !== '' && strtoupper(trim($record->issuerNif)) !== strtoupper(trim($issuer->nif))) {
            return ['record' => $record, 'errors' => $this->validator->issuerMismatchErrors()];
        }

        $beforeEvent = new BeforeBillingRecordGenerationEvent($record);
        $this->eventDispatcher->dispatch($beforeEvent, VerifactuEvents::BEFORE_BILLING_RECORD_GENERATION);
        $record = $beforeEvent->getRecord();

        // The AEAT environment of this call: the record is chained, stored and submitted for it.
        $environment = $this->environmentProvider?->getEnvironment() ?? AeatEnvironment::fromValue($this->defaultEnvironment);

        // An empty generation time is stamped inside the chain lock, so FechaHoraHusoGenRegistro follows chain order.
        $stampInLock = trim($record->generatedAt) === '';
        $errors      = $this->validator->validate($stampInLock ? $this->copy($record, generatedAt: GenerationTimestamp::now($this->clock)) : $record);
        if ($errors !== []) {
            return ['record' => $record, 'errors' => $errors];
        }

        // The lock passes the head it read with its locking read; a repository that passes nothing gets getLastState().
        $operation = fn (?HashChainState ...$locked): array => $this->chainRecord(
            $stampInLock ? $this->copy($record, generatedAt: GenerationTimestamp::now($this->clock)) : $record,
            $persistChainState,
            $software,
            $locked === [] ? $this->hashChainRepository->getLastState($record->issuerNif) : $locked[0],
            $environment,
        );
        $chained = $persistChainState && $this->hashChainRepository instanceof LockableHashChainRepositoryInterface
            ? $this->hashChainRepository->withChainLock($record->issuerNif, $operation)
            : $operation();

        if ($chained['errors'] !== []) {
            return $chained;
        }

        $record = $chained['record'];

        // From here on the chain head already points at this record. Listeners receive copies (they cannot change the
        // hash, XML or signed XML that is submitted and returned), and a failing (informational) listener must neither
        // stop the submission nor hide the record or its result: either would lose a fiscal record or fork the chain
        // on a retry. Use BEFORE_BILLING_RECORD_GENERATION to veto a record.
        $eventErrors = [];
        $this->dispatchObserverEvent(
            new AfterBillingRecordGeneratedEvent(clone $record),
            VerifactuEvents::AFTER_BILLING_RECORD_GENERATED,
            $eventErrors,
        );

        $result = ['record' => $record, 'errors' => []];

        if ($submitToAeat) {
            $this->dispatchObserverEvent(
                new BeforeAeatSubmissionEvent(clone $record),
                VerifactuEvents::BEFORE_AEAT_SUBMISSION,
                $eventErrors,
            );

            $submission           = $this->submissionClient->submit($record);
            $result['submission'] = $submission;
            $storeError           = $this->recordSubmission($record, $submission, $persistChainState);
            if ($storeError instanceof Throwable) {
                $result['store_errors'] = [$storeError];
            }

            $this->dispatchObserverEvent(
                new AfterAeatSubmissionEvent(clone $record, $submission),
                VerifactuEvents::AFTER_AEAT_SUBMISSION,
                $eventErrors,
            );
        }

        if ($eventErrors !== []) {
            $result['event_errors'] = $eventErrors;
        }

        return $result;
    }

    /**
     * Chains several records of ONE issuer in the given order, in one chain lock (V7): record n+1 chains onto record n.
     * All or nothing: when any record fails (issuer check, veto, validation, XSD, signing) nothing is chained, stored or
     * appended, so the chain head never points at a record that was not stored (and will not be sent). The records
     * are not submitted here: BillingRecordBatchSubmitter sends the stored records later, up to 1000 per request.
     *
     * AFTER_BILLING_RECORD_GENERATED is dispatched once per record after the lock returns (as for process(): inside a
     * host transaction that is before the host commits; listeners that need the commit must defer).
     *
     * @param list<BillingRecord> $records
     *
     * @return array{records: list<BillingRecord>, errors: array<int, list<string>>, event_errors?: list<array{event: string, exception: Throwable}>}
     */
    public function processBatch(array $records, bool $persistChainState = true): array
    {
        if ($records === []) {
            return ['records' => [], 'errors' => []];
        }

        $issuer   = $this->resolveIssuer();
        $software = $this->resolveSoftwareIdentity();
        $prepared = [];
        $errors   = [];
        foreach (array_values($records) as $index => $record) {
            if (trim($record->issuerNif) === '' && trim($issuer->nif) !== '') {
                $record = $this->copy($record, issuerNif: $issuer->nif);
            }
            if ($record->issuerName === null && $issuer->name !== '') {
                $record = $this->copy($record, issuerName: $issuer->name);
            }
            if ($this->enforceProviderIssuer && trim($issuer->nif) !== '' && strtoupper(trim($record->issuerNif)) !== strtoupper(trim($issuer->nif))) {
                $errors[$index] = $this->validator->issuerMismatchErrors();

                continue;
            }
            $beforeEvent = new BeforeBillingRecordGenerationEvent($record);
            $this->eventDispatcher->dispatch($beforeEvent, VerifactuEvents::BEFORE_BILLING_RECORD_GENERATION);
            $record       = $beforeEvent->getRecord();
            $stampInLock  = trim($record->generatedAt) === '';
            $recordErrors = $this->validator->validate($stampInLock ? $this->copy($record, generatedAt: GenerationTimestamp::now($this->clock)) : $record);
            if ($recordErrors !== []) {
                $errors[$index] = $recordErrors;

                continue;
            }
            $prepared[$index] = [$record, $stampInLock];
        }

        $issuerNif = strtoupper(trim($prepared[array_key_first($prepared) ?? 0][0]->issuerNif ?? ''));
        foreach ($prepared as $index => [$record]) {
            if (strtoupper(trim($record->issuerNif)) !== $issuerNif) {
                $errors[$index] = ['processBatch() chains the records of one issuer; record ' . $index . ' belongs to another issuer.'];
            }
        }
        if ($errors !== []) {
            ksort($errors);

            return ['records' => [], 'errors' => $errors];
        }

        $environment = $this->environmentProvider?->getEnvironment() ?? AeatEnvironment::fromValue($this->defaultEnvironment);
        $operation   = function (?HashChainState ...$locked) use ($prepared, $software, $environment, $issuerNif, $persistChainState): array {
            $head    = $locked === [] ? $this->hashChainRepository->getLastState($issuerNif) : $locked[0];
            $chained = [];
            foreach ($prepared as $index => [$record, $stampInLock]) {
                $result = $this->buildChained(
                    $stampInLock ? $this->copy($record, generatedAt: GenerationTimestamp::now($this->clock)) : $record,
                    $software,
                    $head,
                    $environment,
                );
                if ($result['errors'] !== []) {
                    return ['records' => [], 'errors' => [$index => $result['errors']]];
                }
                $chained[] = $result['record'];
                $head      = $result['state'];
            }
            if ($persistChainState && $head instanceof HashChainState && $chained !== []) {
                $this->persistChained($chained, $head);
            }

            return ['records' => $chained, 'errors' => []];
        };
        $result = $persistChainState && $this->hashChainRepository instanceof LockableHashChainRepositoryInterface
            ? $this->hashChainRepository->withChainLock($issuerNif, $operation)
            : $operation();
        if ($result['errors'] !== []) {
            return $result;
        }

        $eventErrors = [];
        foreach ($result['records'] as $record) {
            $this->dispatchObserverEvent(new AfterBillingRecordGeneratedEvent(clone $record), VerifactuEvents::AFTER_BILLING_RECORD_GENERATED, $eventErrors);
        }
        if ($eventErrors !== []) {
            $result['event_errors'] = $eventErrors;
        }

        return $result;
    }

    /**
     * Same as process(), returned as a typed value object.
     */
    public function processToResult(BillingRecord $record, bool $submitToAeat = false, bool $persistChainState = true): BillingRecordResult
    {
        return BillingRecordResult::fromArray($this->process($record, $submitToAeat, $persistChainState));
    }

    /**
     * Same as processBatch(), returned as a typed value object.
     *
     * @param list<BillingRecord> $records
     */
    public function processBatchToResult(array $records, bool $persistChainState = true): BillingRecordBatchResult
    {
        return BillingRecordBatchResult::fromArray($this->processBatch($records, $persistChainState));
    }

    /**
     * Chains the record onto the given head, hashes, renders, validates and signs it, then stores the new head.
     *
     * Runs inside the repository chain lock when available (read-then-write must be atomic per issuer), with the head
     * the lock read, so the record store append joins the same transaction as the chain head.
     *
     * @return array{record: BillingRecord, errors: list<string>}
     */
    private function chainRecord(BillingRecord $record, bool $persistChainState, SoftwareIdentity $software, ?HashChainState $previousState, AeatEnvironment $environment): array
    {
        $chained = $this->buildChained($record, $software, $previousState, $environment);
        if ($chained['errors'] === [] && $persistChainState) {
            $this->persistChained([$chained['record']], $chained['state']);
        }

        return ['record' => $chained['record'], 'errors' => $chained['errors']];
    }

    /**
     * Chains the record onto the given head, hashes, renders, validates and signs it, without storing anything.
     *
     * @return array{record: BillingRecord, errors: list<string>, state: HashChainState}
     */
    private function buildChained(BillingRecord $record, SoftwareIdentity $software, ?HashChainState $previousState, AeatEnvironment $environment): array
    {
        // Never chain a record of one AEAT environment onto a head of another (a production record onto a test one):
        // switching needs an explicit reset of the issuer's chain (nowo:verifactu:reset-chain).
        $headEnvironment = $previousState->environment ?? AeatEnvironment::fromValue($this->defaultEnvironment);
        if ($previousState instanceof HashChainState && $headEnvironment !== $environment) {
            throw new ChainEnvironmentMismatchException($record->issuerNif, $headEnvironment, $environment);
        }

        $record = $this->copy(
            $record,
            totalTaxAmount: Amount::canonical($record->totalTaxAmount),
            totalAmount: Amount::canonical($record->totalAmount),
            chainTo: $previousState,
            environment: $environment,
        );

        $hash   = $this->hashChainGenerator->computeHash($record);
        $record = $record->withHash($hash, $previousState?->hash);
        $xml    = $this->xmlGenerator->generate(
            $record,
            ['name' => (string) $record->issuerName],
            $software->toSoftwareConfig(),
            $software->toInstallationConfig(),
            $previousState,
        );
        $record = $record->withXml($xml);

        $schemaType = $record->recordType === RecordType::Alta
            ? XsdValidator::SCHEMA_REGISTRO_ALTA
            : XsdValidator::SCHEMA_REGISTRO_ANULACION;
        $state     = new HashChainState($record->issuerNif, $record->invoiceSeriesNumber, $record->issueDate, $hash, $environment);
        $xsdErrors = $this->xsdValidator->validate($xml, $schemaType);
        if ($xsdErrors !== []) {
            return ['record' => $record, 'errors' => $xsdErrors, 'state' => $state];
        }

        if ($this->shouldSign()) {
            if (!$this->recordSigner instanceof BillingRecordSignerInterface) {
                return ['record' => $record, 'errors' => ['XAdES signing is required but no signer service is configured.'], 'state' => $state];
            }

            $record = $record->withSignedXml($this->recordSigner->sign($xml));
        }

        return ['record' => $record, 'errors' => [], 'state' => $state];
    }

    /**
     * Stores the new chain head and appends the records to the record store, in chain order (inside the chain lock).
     *
     * @param non-empty-list<BillingRecord> $records
     */
    private function persistChained(array $records, HashChainState $head): void
    {
        $this->hashChainRepository->storeLastState($head);
        foreach ($records as $record) {
            $this->recordStore?->append($record);
        }
    }

    /**
     * Stores the AEAT outcome in the record store; a store failure is logged and returned, never thrown (the record is
     * already registered at the AEAT).
     *
     * @param array<string, mixed> $submission
     */
    private function recordSubmission(BillingRecord $record, array $submission, bool $persistChainState): ?Throwable
    {
        if (!$persistChainState || !$this->recordStore instanceof BillingRecordStoreInterface) {
            return null;
        }
        try {
            $reference = $submission['reference'] ?? null;
            $this->recordStore->markSubmitted(
                $record->issuerNif,
                (string) $record->hash,
                ($submission['success'] ?? false) === true,
                is_string($reference) ? $reference : null,
            );
        } catch (Throwable $exception) {
            $this->logger?->error('The record store could not save the AEAT submission outcome ({exception_class}); the submission result is kept.', [
                'exception_class' => $exception::class,
            ]);

            return $exception;
        }

        return null;
    }

    private function resolveIssuer(): IssuerIdentity
    {
        return ($this->issuerProvider ?? new ConfigIssuerProvider($this->issuerConfig))->getIssuer();
    }

    private function resolveSoftwareIdentity(): SoftwareIdentity
    {
        return ($this->softwareIdentityProvider ?? new ConfigSoftwareIdentityProvider($this->softwareConfig, $this->installationConfig))
            ->getSoftwareIdentity();
    }

    /**
     * Copies a record before chaining (no hash, XML or signature yet), changing the given fields.
     *
     * @param false|HashChainState|null $chainTo false keeps the record's previous hash; null or a state replaces it
     */
    private function copy(
        BillingRecord $record,
        ?string $issuerNif = null,
        ?string $totalTaxAmount = null,
        ?string $totalAmount = null,
        HashChainState|false|null $chainTo = false,
        ?string $generatedAt = null,
        ?string $issuerName = null,
        ?AeatEnvironment $environment = null,
    ): BillingRecord {
        return new BillingRecord(
            $record->recordType,
            $issuerNif ?? $record->issuerNif,
            $record->invoiceSeriesNumber,
            $record->issueDate,
            $record->invoiceType,
            $totalTaxAmount ?? $record->totalTaxAmount,
            $totalAmount ?? $record->totalAmount,
            $generatedAt ?? $record->generatedAt,
            $record->lines,
            $record->taxBreakdown,
            $chainTo === false ? $record->previousHash : $chainTo?->hash,
            issuerName: $issuerName ?? $record->issuerName,
            operationDescription: $record->operationDescription,
            recipients: $record->recipients,
            rectificationType: $record->rectificationType,
            rectifiedInvoices: $record->rectifiedInvoices,
            substitutedInvoices: $record->substitutedInvoices,
            rectificationAmount: $record->rectificationAmount,
            operationDate: $record->operationDate,
            environment: $environment ?? $record->environment,
            correction: $record->correction,
            previousRejection: $record->previousRejection,
            withoutPreviousRecord: $record->withoutPreviousRecord,
        );
    }

    /**
     * Dispatches an event raised after the chain head moved (generated, before / after AEAT submission) and contains
     * any listener failure.
     *
     * @param list<array{event: string, exception: Throwable}> $eventErrors
     */
    private function dispatchObserverEvent(object $event, string $eventName, array &$eventErrors): void
    {
        try {
            $this->eventDispatcher->dispatch($event, $eventName);
        } catch (Throwable $exception) {
            // No record data and no exception message in the log: both may carry personal data (NIF, names).
            $this->logger?->error('A {event} listener failed ({exception_class}); the billing record, its submission and its result are kept.', [
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
