<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Messenger;

/**
 * Asks a Messenger worker to send the waiting billing records of an issuer to the AEAT (BillingRecordBatchSubmitter).
 * Dispatch it after your transaction commits (or route it through a transport: it then runs in the worker). The
 * handler sends the next batch and, while records remain or the AEAT wait time has not elapsed, dispatches itself again
 * with a DelayStamp. Dispatch one message per issuer, not one per invoice: every message keeps rescheduling itself
 * while records wait.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class SubmitPendingBillingRecords
{
    /**
     * @param int $failedRuns consecutive runs without any AEAT answer (set by the handler when it reschedules itself)
     */
    public function __construct(
        public readonly string $issuerNif,
        public readonly int $failedRuns = 0,
    ) {
    }
}
