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
    /**
     * Wraps the billing record about to be generated.
     */
    public function __construct(
        private BillingRecord $record,
    ) {
    }

    /**
     * Returns the billing record about to be generated.
     */
    public function getRecord(): BillingRecord
    {
        return $this->record;
    }

    /**
     * Replaces the billing record before generation (listeners may enrich it).
     */
    public function setRecord(BillingRecord $record): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->record = $record;
    }
}
