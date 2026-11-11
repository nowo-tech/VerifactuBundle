<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Client;

use Nowo\VerifactuBundle\Model\AeatBatchResponse;
use Nowo\VerifactuBundle\Model\BillingRecord;

/**
 * Sends several billing records of one issuer and one AEAT environment in a single SuministroLR request (at most
 * SoapEnvelopeBuilder::MAX_RECORDS_PER_SUBMISSION) and returns the per-record answer. Used by
 * Service\BillingRecordBatchSubmitter; a host client that only implements AeatSubmissionClientInterface keeps working
 * for single records.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
interface BatchAeatSubmissionClientInterface
{
    /**
     * Implementations call $beforeTransmit once, right before the request leaves the process (after the envelope and
     * the certificate are ready), and never when nothing is sent: the submitter counts an attempt there. A transport
     * failure (no HTTP answer) is returned as AeatBatchResponse::failed(..., transportFailure: true).
     *
     * @param list<BillingRecord> $records chained, stored records, in chain order
     * @param (callable(): void)|null $beforeTransmit
     */
    public function submitBatch(array $records, ?callable $beforeTransmit = null): AeatBatchResponse;
}
