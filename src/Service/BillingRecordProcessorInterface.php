<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Service;

use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\BillingRecordBatchResult;
use Nowo\VerifactuBundle\Model\BillingRecordResult;
use Throwable;

/**
 * Public contract of the billing-record processor (REQ-ARCH-001 rule 1).
 *
 * Type-hint this interface, not the final BillingRecordProcessor. A host may replace the implementation by re-aliasing
 * it. The array-returning methods keep the shape they always had; processToResult() and processBatchToResult() return
 * the same outcome as typed value objects.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
interface BillingRecordProcessorInterface
{
    /**
     * Chains, renders, validates and signs the record, stores the new chain head and optionally submits it to the AEAT.
     *
     * @return array{record: BillingRecord, errors: list<string>, submission?: array<string, mixed>, event_errors?: list<array{event: string, exception: Throwable}>, store_errors?: list<Throwable>}
     */
    public function process(BillingRecord $record, bool $submitToAeat = false, bool $persistChainState = true): array;

    /**
     * Chains several records of ONE issuer in the given order, in one chain lock; all or nothing.
     *
     * @param list<BillingRecord> $records
     *
     * @return array{records: list<BillingRecord>, errors: array<int, list<string>>, event_errors?: list<array{event: string, exception: Throwable}>}
     */
    public function processBatch(array $records, bool $persistChainState = true): array;

    /**
     * Same as process(), returned as a typed value object.
     */
    public function processToResult(BillingRecord $record, bool $submitToAeat = false, bool $persistChainState = true): BillingRecordResult;

    /**
     * Same as processBatch(), returned as a typed value object.
     *
     * @param list<BillingRecord> $records
     */
    public function processBatchToResult(array $records, bool $persistChainState = true): BillingRecordBatchResult;
}
