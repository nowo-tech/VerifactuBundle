<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Support;

use Closure;
use Nowo\VerifactuBundle\Client\BatchAeatSubmissionClientInterface;
use Nowo\VerifactuBundle\Model\AeatBatchResponse;
use Nowo\VerifactuBundle\Model\AeatRecordResponse;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordSubmissionStatus;
use RuntimeException;

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
    /** Script entry: the client throws before transmitting anything. */
    public const NOT_SENT = 'not-sent';

    /** @var list<list<BillingRecord>> */
    public array $requests = [];

    /** @var list<Closure|string> */
    private array $script;

    /**
     * @param Closure|string ...$script one answer per request (a Closure(list<BillingRecord>): AeatBatchResponse, or
     *                                  NOT_SENT), then the default
     */
    public function __construct(Closure|string ...$script)
    {
        $this->script = array_values($script);
    }

    public function submitBatch(array $records, ?callable $beforeTransmit = null): AeatBatchResponse
    {
        $answer = array_shift($this->script);
        if ($answer === self::NOT_SENT) {
            // Fails before anything leaves the process (no certificate, envelope error): not an attempt.
            throw new RuntimeException('not sent');
        }
        if ($beforeTransmit !== null) {
            $beforeTransmit();
        }
        $this->requests[] = $records;

        if (!$answer instanceof Closure) {
            return self::allAccepted($records);
        }
        $response = $answer($records);
        if (!$response instanceof AeatBatchResponse) {
            throw new RuntimeException('A script entry must return an AeatBatchResponse.');
        }

        return $response;
    }

    /**
     * @param array<BillingRecord> $records
     */
    public static function allAccepted(array $records, int $wait = 60): AeatBatchResponse
    {
        return new AeatBatchResponse(
            AeatBatchResponse::ENVIO_CORRECT,
            array_values(array_map(static fn (BillingRecord $record): AeatRecordResponse => self::line($record, RecordSubmissionStatus::Accepted), $records)),
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
                RecordSubmissionStatus::Accepted                                      => 'Correcto',
                RecordSubmissionStatus::AcceptedWithErrors                            => 'AceptadoConErrores',
                RecordSubmissionStatus::Rejected, RecordSubmissionStatus::Unconfirmed => 'Incorrecto',
            },
            $code,
            $description,
            $duplicateStatus !== null ? 'IDPET-1' : null,
            $duplicateStatus,
        );
    }
}
