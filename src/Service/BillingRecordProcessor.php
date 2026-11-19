<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Service;

use Doctrine\DBAL\Exception as DbalException;
use DOMDocument;
use DOMDocumentType;
use DOMElement;
use InvalidArgumentException;
use LogicException;
use Nowo\VerifactuBundle\Client\AeatSubmissionClientInterface;
use Nowo\VerifactuBundle\Clock\GenerationTimestamp;
use Nowo\VerifactuBundle\Event\AfterAeatSubmissionEvent;
use Nowo\VerifactuBundle\Event\AfterBillingRecordGeneratedEvent;
use Nowo\VerifactuBundle\Event\BeforeAeatSubmissionEvent;
use Nowo\VerifactuBundle\Event\BeforeBillingRecordGenerationEvent;
use Nowo\VerifactuBundle\Event\VerifactuEvents;
use Nowo\VerifactuBundle\Exception\ChainEnvironmentMismatchException;
use Nowo\VerifactuBundle\Exception\ChainTransactionLostException;
use Nowo\VerifactuBundle\Exception\SubmittedRecordNotCommittedException;
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
use Nowo\VerifactuBundle\Repository\TransactionStateAwareInterface;
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
use function count;
use function is_bool;
use function is_string;
use function ksort;

use const LIBXML_NONET;

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
     * contained the same way and returned in `store_errors`. Exception: a database error thrown by an
     * `AFTER_BILLING_RECORD_GENERATED` or `BEFORE_AEAT_SUBMISSION` listener while your transaction is open on the chain's
     * connection is rethrown (nothing was sent to the AEAT; roll back and replay your whole unit of work).
     *
     * With `submitToAeat: true` inside your transaction (logged as a warning), the chain head is read again before the
     * AEAT request (ChainTransactionLostException when it is gone) and after it: a database failure after the request,
     * or a head that is gone, throws SubmittedRecordNotCommittedException with the record and the AEAT answer.
     *
     * @throws ChainTransactionLostException
     * @throws SubmittedRecordNotCommittedException
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
        // Whether the head is still inside the host's transaction, read once, before any listener runs: a listener that
        // opens (and leaks) a transaction must never make a committed record look uncommitted (a host retry would then
        // add a duplicate record).
        $hostTransaction = $this->transactionalChainRepository();

        // From here on the chain head already points at this record. Listeners receive copies (they cannot change the
        // hash, XML or signed XML that is submitted and returned), and a failing (informational) listener must neither
        // stop the submission nor hide the record or its result: either would lose a fiscal record or fork the chain
        // on a retry. Use BEFORE_BILLING_RECORD_GENERATION to veto a record. Exception (REQ-RUNTIME-003 rule 8): before
        // the AEAT submission, a database failure inside an open transaction is rethrown, see dispatchObserverEvent().
        $eventErrors = [];
        $this->dispatchObserverEvent(
            new AfterBillingRecordGeneratedEvent(clone $record),
            VerifactuEvents::AFTER_BILLING_RECORD_GENERATED,
            $eventErrors,
            $hostTransaction,
        );

        $result = ['record' => $record, 'errors' => []];

        if ($submitToAeat) {
            $this->dispatchObserverEvent(
                new BeforeAeatSubmissionEvent(clone $record),
                VerifactuEvents::BEFORE_AEAT_SUBMISSION,
                $eventErrors,
                $hostTransaction,
            );

            // Inside the host's transaction the head commits only with it: check it is still there before sending (a
            // listener may have swallowed a database error), and again after (UO-VF-1 / UO-VF-2).
            $transactional = $persistChainState ? $hostTransaction : null;
            if ($transactional instanceof TransactionStateAwareInterface) {
                $this->logger?->warning('A Veri*Factu record is submitted to the AEAT inside an open database transaction: the chain row stays locked during the AEAT request, and the record is at the AEAT even if the transaction rolls back. Prefer the record store with the batch submitter (docs/CONCURRENCY.md).');
                $transactional->verifyHeadInTransaction($record->issuerNif, (string) $record->hash);
            }

            $submission           = $this->submissionClient->submit($record);
            $result['submission'] = $submission;
            $storeError           = $this->recordSubmission($record, $submission, $persistChainState);
            if ($storeError instanceof Throwable) {
                $result['store_errors'] = [$storeError];
            }

            $afterErrors = [];
            $this->dispatchObserverEvent(
                new AfterAeatSubmissionEvent(clone $record, $submission),
                VerifactuEvents::AFTER_AEAT_SUBMISSION,
                $afterErrors,
            );
            $eventErrors = [...$eventErrors, ...$afterErrors];
            if ($transactional instanceof TransactionStateAwareInterface) {
                $this->guardSubmittedRecord($transactional, $record, $submission, [$storeError, ...array_column($afterErrors, 'exception')]);
            }
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

        $eventErrors     = [];
        $hostTransaction = $this->transactionalChainRepository();
        foreach ($result['records'] as $record) {
            $this->dispatchObserverEvent(new AfterBillingRecordGeneratedEvent(clone $record), VerifactuEvents::AFTER_BILLING_RECORD_GENERATED, $eventErrors, $hostTransaction);
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
     * Repairs after SubmittedRecordNotCommittedException: stores exactly the record the AEAT received as the issuer's
     * chain head (and in the record store, with its AEAT answer), in the chain lock. Run it after rolling back the failed
     * transaction, outside any transaction (LogicException otherwise: a later rollback would undo the repair), before
     * chaining anything else for the issuer.
     *
     * Returns true when the record is the head afterwards (stored now, or already the head; the AEAT answer is recorded
     * in both cases), false when the head has moved to another record meanwhile: the chain is then forked at the AEAT,
     * nothing is written, and the issuer needs a manual correction (`nowo:verifactu:verify-chain`,
     * docs/CONCURRENCY.md). Not part of BillingRecordProcessorInterface (a minor version cannot add methods to it).
     *
     * It accepts only the record of SubmittedRecordNotCommittedException, unchanged; it is not an import API. The
     * record is refused (InvalidArgumentException, nothing written) when its hash is not the fingerprint of its fields
     * and previous hash, its parsed XML does not carry that fingerprint as its own Huella with the same invoice id and
     * previous Huella, its AEAT environment is not the configured one or the head's, (with `providers.issuer` enforced)
     * it belongs to another issuer, or the answer has no boolean `success` or a non-string `reference`. These checks
     * catch a forged or altered record; they do not prove the AEAT holds it, nor that fields outside the fingerprint and
     * the invoice id (lines, recipients, description) were not changed after the exception: pass it unchanged.
     *
     * @param BillingRecord $record the record carried by SubmittedRecordNotCommittedException (hash set)
     * @param array<string, mixed> $submission the AEAT answer carried by the exception
     *
     * @throws InvalidArgumentException when the record is not a chained record of this configuration
     */
    public function persistSubmittedRecord(BillingRecord $record, array $submission): bool
    {
        if ($this->transactionalChainRepository() instanceof TransactionStateAwareInterface) {
            throw new LogicException('persistSubmittedRecord() must run after the failed transaction was rolled back, outside any transaction: inside it, a later rollback would undo the repair (MySQL / MariaDB) or the aborted transaction would refuse it (PostgreSQL).');
        }
        $hash = strtoupper(trim((string) $record->hash));
        $this->assertSubmittedRecord($record, $hash);
        if (!is_bool($submission['success'] ?? null) || ($submission['reference'] ?? null) !== null && !is_string($submission['reference'])) {
            throw new InvalidArgumentException('The AEAT answer must have a boolean "success" and a string or null "reference": pass the unchanged submission of SubmittedRecordNotCommittedException.');
        }
        $previous  = $record->previousHash === null ? null : strtoupper(trim($record->previousHash));
        $reference = $submission['reference'] ?? null;
        $answer    = fn () => $this->recordStore?->markSubmitted($record->issuerNif, $hash, $submission['success'], is_string($reference) ? $reference : null);
        $operation = function (?HashChainState ...$locked) use ($record, $hash, $previous, $answer): bool {
            $head = $locked === [] ? $this->hashChainRepository->getLastState($record->issuerNif) : $locked[0];
            if ($head?->environment instanceof AeatEnvironment && $head->environment !== $record->environment) {
                throw new InvalidArgumentException('The record\'s AEAT environment is not the chain head\'s: it is not the record this chain sent.');
            }
            $headHash = $head instanceof HashChainState ? strtoupper(trim($head->hash)) : null;
            if ($headHash === $hash) {
                // Already the head (an earlier repair, or the transaction did commit): record the answer, idempotently,
                // so the batch submitter does not send the record again.
                $answer();

                return true;
            }
            if ($headHash !== $previous) {
                return false;
            }
            $this->persistChained(
                [$record],
                new HashChainState($record->issuerNif, $record->invoiceSeriesNumber, $record->issueDate, $hash, $record->environment),
            );
            $answer();

            return true;
        };

        return $this->hashChainRepository instanceof LockableHashChainRepositoryInterface
            ? $this->hashChainRepository->withChainLock($record->issuerNif, $operation)
            : $operation();
    }

    /**
     * persistSubmittedRecord() input checks: the record must be one this processor chained and sent, unchanged.
     */
    private function assertSubmittedRecord(BillingRecord $record, string $hash): void
    {
        if ($hash === '' || !hash_equals($this->hashChainGenerator->computeHash($record), $hash)) {
            throw new InvalidArgumentException('The record\'s hash is not the fingerprint of its fields and previous hash: pass the unchanged record of SubmittedRecordNotCommittedException.');
        }
        if (!$this->xmlDescribesRecord($record, $hash)) {
            throw new InvalidArgumentException('The record\'s XML does not describe this record (its own Huella, invoice id and previous Huella): pass the unchanged record of SubmittedRecordNotCommittedException.');
        }
        $environment = $this->environmentProvider?->getEnvironment() ?? AeatEnvironment::fromValue($this->defaultEnvironment);
        if ($record->environment !== $environment) {
            throw new InvalidArgumentException('The record\'s AEAT environment is not the configured one.');
        }
        $issuer = $this->resolveIssuer();
        if ($this->enforceProviderIssuer && trim($issuer->nif) !== '' && strtoupper(trim($record->issuerNif)) !== strtoupper(trim($issuer->nif))) {
            throw new InvalidArgumentException('The record belongs to another issuer than the issuer provider\'s.');
        }
    }

    /**
     * Whether the record's XML (RegistroAlta / RegistroAnulacion, as BillingRecordXmlGenerator renders it, in the
     * SuministroInformacion namespace) carries the record's own Huella, its invoice id (issuer, series and number,
     * issue date) and its link to the previous record (RegistroAnterior Huella, or no RegistroAnterior when it has
     * none). Checked on the parsed XML, never by text search: no DOCTYPE (so no entities), and exactly one of each
     * checked element, in that namespace.
     */
    private function xmlDescribesRecord(BillingRecord $record, string $hash): bool
    {
        $root      = $this->parseRecordXml((string) $record->xml);
        $anulacion = $record->recordType === RecordType::Anulacion;
        if (!$root instanceof DOMElement || $root->localName !== ($anulacion ? 'RegistroAnulacion' : 'RegistroAlta') || $root->namespaceURI !== BillingRecordXmlGenerator::NS_SUMINISTRO) {
            return false;
        }
        $suffix = $anulacion ? 'Anulada' : '';
        $id     = $this->singleChild($root, 'IDFactura');
        $chain  = $this->singleChild($root, 'Encadenamiento');
        if (!$id instanceof DOMElement || !$chain instanceof DOMElement) {
            return false;
        }
        if ($record->previousHash === null) {
            $linked = $this->children($chain, 'RegistroAnterior') === [];
        } else {
            $previous = $this->singleChild($chain, 'RegistroAnterior');
            $linked   = $previous instanceof DOMElement
                && strtoupper(trim((string) $this->singleChild($previous, 'Huella')?->textContent)) === strtoupper(trim($record->previousHash));
        }

        return $linked
            && strtoupper(trim((string) $this->singleChild($root, 'Huella')?->textContent)) === $hash
            && $this->singleChild($id, 'IDEmisorFactura' . $suffix)?->textContent === $record->issuerNif
            && $this->singleChild($id, 'NumSerieFactura' . $suffix)?->textContent === $record->invoiceSeriesNumber
            && $this->singleChild($id, 'FechaExpedicionFactura' . $suffix)?->textContent === $record->issueDate;
    }

    /**
     * Parses a record XML fragment without network access; null when it is not well-formed or declares a DOCTYPE (a
     * record never has one; refusing it rules out entities and DTDs). The libxml error flag is restored afterwards
     * (worker mode).
     */
    private function parseRecordXml(string $xml): ?DOMElement
    {
        if ($xml === '' || stripos($xml, '<!DOCTYPE') !== false) {
            return null;
        }
        $document          = new DOMDocument();
        $previousUseErrors = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseErrors);
        }

        // The string check above misses other encodings (UTF-16): refuse any DTD once the document is parsed too.
        return $loaded && !$document->doctype instanceof DOMDocumentType ? $document->documentElement : null;
    }

    /**
     * The direct child elements with this local name, in any namespace.
     *
     * @return list<DOMElement>
     */
    private function children(DOMElement $parent, string $localName): array
    {
        $children = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                $children[] = $child;
            }
        }

        return $children;
    }

    /**
     * The only direct child element with this local name, when there is exactly one and it is in the
     * SuministroInformacion namespace; null otherwise (missing, duplicated, or in another namespace).
     */
    private function singleChild(DOMElement $parent, string $localName): ?DOMElement
    {
        $children = $this->children($parent, $localName);

        return count($children) === 1 && $children[0]->namespaceURI === BillingRecordXmlGenerator::NS_SUMINISTRO ? $children[0] : null;
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
     * One exception (REQ-RUNTIME-003 rule 8): before anything is sent to the AEAT, a database failure is rethrown when
     * $hostTransaction is set: the chain repository whose connection was inside the host's transaction around
     * process() when the chain lock returned (read before any listener ran). The new chain head is not committed yet, and the database may already have ended the host's
     * transaction (MySQL / MariaDB deadlock) or aborted it (PostgreSQL: every later statement fails and the COMMIT
     * rolls back): containing it would submit, or return as chained, a record whose chain head never commits, and the
     * host's retry would then chain a second record onto the same predecessor (a forked chain). Outside a transaction
     * the head is already committed, so the failure stays contained (a retry would add a second fiscal record).
     *
     * @param list<array{event: string, exception: Throwable}> $eventErrors
     */
    private function dispatchObserverEvent(object $event, string $eventName, array &$eventErrors, ?TransactionStateAwareInterface $hostTransaction = null): void
    {
        try {
            $this->eventDispatcher->dispatch($event, $eventName);
        } catch (Throwable $exception) {
            $transactional = $hostTransaction instanceof TransactionStateAwareInterface && $this->isDatabaseFailure($exception) ? $hostTransaction : null;
            if ($transactional instanceof TransactionStateAwareInterface) {
                // @igor-ignore - Marks the host's current database transaction rollback-only, not service state.
                $transactional->setRollbackOnly();
                $this->logger?->error('A {event} listener failed with a database error ({exception_class}) inside an open transaction; rethrown, the record is not chained until the transaction is replayed.', [
                    'event'           => $eventName,
                    'exception_class' => $exception::class,
                ]);

                throw $exception;
            }
            // No record data and no exception message in the log: both may carry personal data (NIF, names).
            $this->logger?->error('A {event} listener failed ({exception_class}); the billing record, its submission and its result are kept.', [
                'event'           => $eventName,
                'exception_class' => $exception::class,
            ]);
            $eventErrors[] = ['event' => $eventName, 'exception' => $exception];
        }
    }

    /**
     * Whether the failure, or one of its previous exceptions, is a Doctrine DBAL error (DBAL 3 class or DBAL 4
     * interface `Doctrine\DBAL\Exception`).
     */
    private function isDatabaseFailure(Throwable $exception): bool
    {
        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof DbalException) {
                return true;
            }
        }

        return false;
    }

    /**
     * The chain repository when its connection is inside a transaction, null otherwise (or when it cannot tell).
     */
    private function transactionalChainRepository(): ?TransactionStateAwareInterface
    {
        $repository = $this->hashChainRepository;

        return $repository instanceof TransactionStateAwareInterface && $repository->isInsideTransaction() ? $repository : null;
    }

    /**
     * After an AEAT submission inside the host's transaction: a database failure of the record store or of an
     * AFTER_AEAT_SUBMISSION listener, or a head no longer in the transaction, means the head will not commit while the
     * AEAT holds the record. Committing would lose it silently (PostgreSQL aborted transaction, MySQL / MariaDB
     * transaction ended by a deadlock: pdo_pgsql and mysqli commit without an error) and fork the chain at the AEAT, so
     * the host gets the record and the AEAT answer in SubmittedRecordNotCommittedException.
     *
     * @param array<string, mixed> $submission
     * @param list<Throwable|null> $failures contained failures after the submission
     *
     * @throws SubmittedRecordNotCommittedException
     */
    private function guardSubmittedRecord(TransactionStateAwareInterface $repository, BillingRecord $record, array $submission, array $failures): void
    {
        $databaseError = null;
        foreach ($failures as $failure) {
            if ($failure instanceof Throwable && $this->isDatabaseFailure($failure)) {
                $databaseError = $failure;

                break;
            }
        }
        $headLoss = null;
        try {
            $repository->verifyHeadInTransaction($record->issuerNif, (string) $record->hash);
        } catch (ChainTransactionLostException $lost) {
            $headLoss = $lost;
        }
        if (!$databaseError instanceof Throwable && !$headLoss instanceof ChainTransactionLostException) {
            return;
        }

        // Class names only: the record (NIF, names) and the messages may carry personal data.
        $this->logger?->critical('A Veri*Factu record was sent to the AEAT but its chain head will not commit with the open transaction (database error: {database_error}; head lost: {head_lost}); SubmittedRecordNotCommittedException carries the record and the AEAT answer to persist. Do not retry process().', [
            'database_error' => $databaseError instanceof Throwable ? $databaseError::class : 'none',
            'head_lost'      => $headLoss instanceof ChainTransactionLostException ? 'yes (' . ($headLoss->getPrevious() instanceof Throwable ? $headLoss->getPrevious()::class : 'other head') . ')' : 'no',
        ]);

        // A host that catches it and commits anyway must fail loudly, never commit (MySQL) or silently roll back
        // (PostgreSQL) without the record the AEAT holds.
        $repository->setRollbackOnly();

        throw new SubmittedRecordNotCommittedException($record, $submission, $databaseError, $headLoss);
    }

    private function shouldSign(): bool
    {
        return $this->signRecords || $this->mode === 'no_verifactu';
    }
}
