<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Event;

use Nowo\VerifactuBundle\Model\BillingRecord;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after hash and XML have been computed for a billing record.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class AfterBillingRecordGeneratedEvent extends Event
{
    /**
     * Wraps the generated billing record.
     */
    public function __construct(
        private readonly BillingRecord $record,
    ) {
    }

    /**
     * Returns the generated billing record (hashed, rendered and signed when applicable).
     */
    public function getRecord(): BillingRecord
    {
        return $this->record;
    }
}
