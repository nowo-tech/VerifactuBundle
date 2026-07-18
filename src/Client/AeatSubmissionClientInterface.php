<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Client;

use Nowo\VerifactuBundle\Model\BillingRecord;

/**
 * Submits billing records to the AEAT Veri*Factu web service.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
interface AeatSubmissionClientInterface
{
    /**
     * @return array{success: bool, reference?: string, errors?: list<string>}
     */
    public function submit(BillingRecord $record): array;
}
