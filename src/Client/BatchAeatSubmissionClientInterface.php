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
     * @param list<BillingRecord> $records chained, stored records, in chain order
     */
    public function submitBatch(array $records): AeatBatchResponse;
}
