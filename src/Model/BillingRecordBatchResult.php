<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Model;

use Throwable;

/**
 * Typed outcome of BillingRecordProcessorInterface::processBatchToResult(): the array returned by processBatch(), as a value object.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class BillingRecordBatchResult
{
    /**
     * @param list<BillingRecord> $records the chained records (empty when any record failed: all or nothing)
     * @param array<int, list<string>> $errors validation errors keyed by the index of the input record
     * @param list<array{event: string, exception: Throwable}> $eventErrors failures of informational listeners
     */
    public function __construct(
        public readonly array $records = [],
        public readonly array $errors = [],
        public readonly array $eventErrors = [],
    ) {
    }

    /**
     * Builds the value object from the array shape returned by processBatch().
     *
     * @param array{records: list<BillingRecord>, errors: array<int, list<string>>, event_errors?: list<array{event: string, exception: Throwable}>} $result
     */
    public static function fromArray(array $result): self
    {
        return new self($result['records'], $result['errors'], $result['event_errors'] ?? []);
    }

    /** Whether every record was chained (no validation errors). */
    public function isSuccessful(): bool
    {
        return $this->errors === [];
    }
}
