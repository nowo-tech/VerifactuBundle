<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Event;

use DateTimeImmutable;
use Nowo\VerifactuBundle\Model\AeatBatchResponse;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched once when the batch submitter pauses an issuer (VerifactuEvents::ISSUER_SUBMISSION_PAUSED): the AEAT
 * refused, as a whole, a record sent on its own `max_attempts` times AND the next record sent on its own (or there was
 * no other record to try). The error is about the issuer, not a record: a header error (e.g. Codigo[4104]), the
 * certificate, the endpoint. No record is marked failed; every record stays pending, and the issuer is not sent until
 * `pausedUntil`. Alert someone: fix the cause; the next submission after the pause tries again. Dispatched after every
 * stored change is committed; a listener failure changes nothing.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class IssuerSubmissionPausedEvent extends Event
{
    /**
     * @param string $failure the AEAT refusal (SoapFault text)
     * @param int|null $faultErrorCode its AEAT Codigo[NNNN], if any
     */
    public function __construct(
        public readonly string $issuerNif,
        public readonly string $failure,
        public readonly ?int $faultErrorCode,
        public readonly DateTimeImmutable $pausedUntil,
        public readonly AeatBatchResponse $response,
    ) {
    }
}
