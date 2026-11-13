<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Model;

use Throwable;

/**
 * Typed outcome of BillingRecordProcessorInterface::processToResult(): the array returned by process(), as a value object.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class BillingRecordResult
{
    /**
     * @param list<string> $errors validation errors; empty when the record was chained
     * @param array<string, mixed>|null $submission raw AEAT submission outcome (only when a submission was requested)
     * @param list<array{event: string, exception: Throwable}> $eventErrors failures of informational listeners
     * @param list<Throwable> $storeErrors failures to record the submission outcome in the record store
     */
    public function __construct(
        public readonly BillingRecord $record,
        public readonly array $errors = [],
        public readonly ?array $submission = null,
        public readonly array $eventErrors = [],
        public readonly array $storeErrors = [],
    ) {
    }

    /**
     * Builds the value object from the array shape returned by process().
     *
     * @param array{record: BillingRecord, errors: list<string>, submission?: array<string, mixed>, event_errors?: list<array{event: string, exception: Throwable}>, store_errors?: list<Throwable>} $result
     */
    public static function fromArray(array $result): self
    {
        return new self(
            $result['record'],
            $result['errors'],
            $result['submission'] ?? null,
            $result['event_errors'] ?? [],
            $result['store_errors'] ?? [],
        );
    }

    /** Whether the record was chained (no validation errors). */
    public function isSuccessful(): bool
    {
        return $this->errors === [];
    }
}
