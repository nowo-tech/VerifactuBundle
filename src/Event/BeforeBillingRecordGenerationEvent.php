<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Event;

use Nowo\VerifactuBundle\Model\BillingRecord;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched before hash/XML generation so integrators can mutate the billing record.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class BeforeBillingRecordGenerationEvent extends Event
{
    public function __construct(
        private BillingRecord $record,
    ) {
    }

    public function getRecord(): BillingRecord
    {
        return $this->record;
    }

    public function setRecord(BillingRecord $record): void
    {
        $this->record = $record;
    }
}
