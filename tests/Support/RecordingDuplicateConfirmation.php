<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Support;

use Nowo\VerifactuBundle\Model\AeatRecordResponse;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Submission\DuplicateConfirmationInterface;
use RuntimeException;

/**
 * Duplicate confirmation double: a fixed answer (or an exception), recording what it was asked.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class RecordingDuplicateConfirmation implements DuplicateConfirmationInterface
{
    /** @var list<string> */
    public array $asked = [];

    public function __construct(
        private readonly ?bool $same,
        private readonly bool $throws = false,
    ) {
    }

    public function isSameRecord(BillingRecord $record, AeatRecordResponse $duplicate): ?bool
    {
        $this->asked[] = $record->invoiceSeriesNumber . '/' . $duplicate->duplicateStatus;
        if ($this->throws) {
            throw new RuntimeException('AEAT query down');
        }

        return $this->same;
    }
}
