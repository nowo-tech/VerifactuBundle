<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Event;

use Nowo\VerifactuBundle\Model\AeatBatchResponse;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when the AEAT gave no answer to two submissions of an issuer in a row (VerifactuEvents::
 * ISSUER_SUBMISSION_STALLED): a transport or TLS error, an HTTP 401 / 403 / 404 / 5xx page, a Server fault. Such errors
 * are never counted against the records (they stay pending) and nothing is failed, so this is the alert: an expired or
 * refused certificate, a wrong endpoint, an AEAT outage. At most once per `submission.pause_seconds` while it lasts;
 * dispatched after the records were put back; a listener failure changes nothing.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class IssuerSubmissionStalledEvent extends Event
{
    public function __construct(
        public readonly string $issuerNif,
        public readonly string $failure,
        public readonly int $httpStatus,
        public readonly AeatBatchResponse $response,
    ) {
    }
}
