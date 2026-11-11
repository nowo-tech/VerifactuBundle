<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Client;

use Nowo\VerifactuBundle\Model\AeatBatchResponse;
use Nowo\VerifactuBundle\Model\AeatRecordResponse;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordSubmissionStatus;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * No-op AEAT client for development; replace in production with SOAP/REST implementation.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsAlias(id: self::SERVICE_NAME, public: true)]
final class NullAeatSubmissionClient implements AeatSubmissionClientInterface, BatchAeatSubmissionClientInterface
{
    public const SERVICE_NAME = 'nowo_verifactu.client.null_aeat_submission';

    /**
     * Does not contact the AEAT; reports a successful sandbox submission with a `SANDBOX-<hash>` reference.
     */
    public function submit(BillingRecord $record): array
    {
        return [
            'success'   => true,
            'reference' => 'SANDBOX-' . ($record->hash ?? 'NO-HASH'),
        ];
    }

    /**
     * Does not contact the AEAT; answers Correcto for every record, with no wait time.
     */
    public function submitBatch(array $records): AeatBatchResponse
    {
        return new AeatBatchResponse(
            AeatBatchResponse::ENVIO_CORRECT,
            array_map(static fn (BillingRecord $record): AeatRecordResponse => new AeatRecordResponse(
                $record->issuerNif,
                $record->invoiceSeriesNumber,
                $record->issueDate,
                $record->recordType,
                RecordSubmissionStatus::Accepted,
                'Correcto',
            ), $records),
            'SANDBOX-' . ($records[0]->hash ?? 'NO-HASH'),
            0,
        );
    }
}
