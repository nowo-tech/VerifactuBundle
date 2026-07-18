<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Service;

use Nowo\VerifactuBundle\Client\AeatSubmissionClientInterface;
use Nowo\VerifactuBundle\Event\AfterBillingRecordGeneratedEvent;
use Nowo\VerifactuBundle\Event\BeforeBillingRecordGenerationEvent;
use Nowo\VerifactuBundle\Event\VerifactuEvents;
use Nowo\VerifactuBundle\Generator\BillingRecordXmlGenerator;
use Nowo\VerifactuBundle\Generator\HashChainGenerator;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\HashChainState;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Repository\HashChainRepositoryInterface;
use Nowo\VerifactuBundle\Signer\BillingRecordSignerInterface;
use Nowo\VerifactuBundle\Validator\AeatBusinessRulesValidator;
use Nowo\VerifactuBundle\Validator\XsdValidator;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

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
    ) {
    }

    /**
     * @return array{record: BillingRecord, errors: list<string>, submission?: array<string, mixed>}
     */
    public function process(BillingRecord $record, bool $submitToAeat = false): array
    {
        $beforeEvent = new BeforeBillingRecordGenerationEvent($record);
        $this->eventDispatcher->dispatch($beforeEvent, VerifactuEvents::BEFORE_BILLING_RECORD_GENERATION);
        $record = $beforeEvent->getRecord();

        $errors = $this->validator->validate($record);
        if ($errors !== []) {
            return ['record' => $record, 'errors' => $errors];
        }

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
            if ($this->recordSigner === null) {
                return ['record' => $record, 'errors' => ['XAdES signing is required but no signer service is configured.']];
            }

            $record = $record->withSignedXml($this->recordSigner->sign($xml));
        }

        $this->hashChainRepository->storeLastState(new HashChainState(
            $record->issuerNif,
            $record->invoiceSeriesNumber,
            $record->issueDate,
            $hash,
        ));

        $this->eventDispatcher->dispatch(
            new AfterBillingRecordGeneratedEvent($record),
            VerifactuEvents::AFTER_BILLING_RECORD_GENERATED,
        );

        $result = ['record' => $record, 'errors' => []];

        if ($submitToAeat) {
            $result['submission'] = $this->submissionClient->submit($record);
        }

        return $result;
    }

    private function shouldSign(): bool
    {
        return $this->signRecords || $this->mode === 'no_verifactu';
    }
}
