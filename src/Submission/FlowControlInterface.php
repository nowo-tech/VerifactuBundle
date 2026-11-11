<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Submission;

use DateTimeImmutable;

/**
 * AEAT flow control (Orden HAC/1177/2024, art. 16.2; Servicios web v1.0.3 §6.4.4.1): after each submission the AEAT
 * returns TiempoEsperaEnvio «t» (initially 60 s); the next submission waits «t» seconds from the previous one, unless
 * it carries the maximum number of records per submission (1000), whichever comes first.
 *
 * The state must be shared by every process that submits (it never lives in a service property: worker mode); the
 * default, CacheFlowControl, keeps it in a PSR-6 pool (`cache.app`).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
interface FlowControlInterface
{
    /**
     * When the next submission for the key may start, or null when it may start now.
     */
    public function nextSubmissionAt(string $key): ?DateTimeImmutable;

    /**
     * Records a submission sent at $sentAt and the wait the AEAT asked for.
     */
    public function registerSubmission(string $key, DateTimeImmutable $sentAt, int $waitSeconds): void;
}
