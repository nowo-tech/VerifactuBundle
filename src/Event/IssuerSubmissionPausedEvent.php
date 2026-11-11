<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Event;

use DateTimeImmutable;
use Nowo\VerifactuBundle\Model\AeatBatchResponse;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched once per pause when the batch submitter pauses an issuer (VerifactuEvents::ISSUER_SUBMISSION_PAUSED); the
 * issuer is not sent until `pausedUntil`, then the submitter tries again. No record is marked failed. Two reasons:
 *
 * - REASON_ISSUER_REFUSED: the AEAT refuses every request of the issuer: a header, certificate or authorisation code
 *   (e.g. Codigo[4104], AeatFaultClassifier::ISSUER), or the suspects AND the newest waiting record were all refused.
 *   Fix the cause.
 * - REASON_AWAITING_PROBE: the record(s) in `heldPositions` were refused on their own with a code that does not say
 *   whose fault it is, and no other record is waiting to test the AEAT with. Check those records; mark one failed with
 *   `nowo:verifactu:submit-pending --issuer=… --fail=<position>` if it is wrong, or wait for the next invoice.
 *
 * `heldPositions` lists the issuer's suspects (held / confirming), also shown by `submit-pending --list`. Dispatched
 * after every stored change is committed; a listener failure changes nothing.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class IssuerSubmissionPausedEvent extends Event
{
    public const REASON_ISSUER_REFUSED = 'issuer_refused';
    public const REASON_AWAITING_PROBE = 'awaiting_probe';

    /**
     * @param string $failure the AEAT refusal (SoapFault text)
     * @param int|null $faultErrorCode its AEAT Codigo[NNNN], if any
     * @param list<int> $heldPositions positions of the issuer's suspects
     */
    public function __construct(
        public readonly string $issuerNif,
        public readonly string $failure,
        public readonly ?int $faultErrorCode,
        public readonly DateTimeImmutable $pausedUntil,
        public readonly AeatBatchResponse $response,
        public readonly string $reason = self::REASON_ISSUER_REFUSED,
        public readonly array $heldPositions = [],
    ) {
    }
}
