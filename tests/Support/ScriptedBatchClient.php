<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Support;

use Closure;
use Nowo\VerifactuBundle\Client\BatchAeatSubmissionClientInterface;
use Nowo\VerifactuBundle\Model\AeatBatchResponse;
use Nowo\VerifactuBundle\Model\AeatRecordResponse;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordSubmissionStatus;

use function count;

/**
 * Batch client for tests: records every request and answers with the given script (default: every record Correcto,
 * TiempoEsperaEnvio 60).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class ScriptedBatchClient implements BatchAeatSubmissionClientInterface
{
    /** @var list<list<BillingRecord>> */
    public array $requests = [];

    /** @var list<Closure(list<BillingRecord>): AeatBatchResponse> */
    private array $script;

    /**
     * @param Closure(list<BillingRecord>): AeatBatchResponse ...$script one answer per request, then the default
     */
    public function __construct(Closure ...$script)
    {
        $this->script = array_values($script);
    }

    public function submitBatch(array $records): AeatBatchResponse
    {
        $this->requests[] = $records;
        $answer           = array_shift($this->script);

        return $answer instanceof Closure ? $answer($records) : self::allAccepted($records);
    }

    /**
     * @param list<BillingRecord> $records
     */
    public static function allAccepted(array $records, int $wait = 60): AeatBatchResponse
    {
        return new AeatBatchResponse(
            AeatBatchResponse::ENVIO_CORRECT,
            array_map(static fn (BillingRecord $record): AeatRecordResponse => self::line($record, RecordSubmissionStatus::Accepted), $records),
            'CSV-' . count($records),
            $wait,
        );
    }

    public static function line(BillingRecord $record, RecordSubmissionStatus $status, ?int $code = null, ?string $description = null, ?string $duplicateStatus = null): AeatRecordResponse
    {
        return new AeatRecordResponse(
            $record->issuerNif,
            $record->invoiceSeriesNumber,
            $record->issueDate,
            $record->recordType,
            $status,
            match ($status) {
                RecordSubmissionStatus::Accepted           => 'Correcto',
                RecordSubmissionStatus::AcceptedWithErrors => 'AceptadoConErrores',
                RecordSubmissionStatus::Rejected           => 'Incorrecto',
            },
            $code,
            $description,
            $duplicateStatus !== null ? 'IDPET-1' : null,
            $duplicateStatus,
        );
    }
}
