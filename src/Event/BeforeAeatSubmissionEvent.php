<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Event;

use Nowo\VerifactuBundle\Model\BillingRecord;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched right before a billing record is submitted to the AEAT (`process(..., submitToAeat: true)`).
 *
 * Informational only: listeners cannot veto or alter the submission. The record is a copy of the one that is sent,
 * so changing it has no effect on the AEAT request. Stopping propagation only skips the remaining listeners.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class BeforeAeatSubmissionEvent extends Event
{
    /**
     * Wraps a copy of the billing record about to be submitted.
     */
    public function __construct(
        private readonly BillingRecord $record,
    ) {
    }

    /**
     * Returns a copy of the billing record about to be submitted (hashed, rendered and signed when applicable).
     */
    public function getRecord(): BillingRecord
    {
        return $this->record;
    }
}
