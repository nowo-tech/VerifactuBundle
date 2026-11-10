<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Clock;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * Formats the dates of a billing record in the Spanish peninsular time zone (Europe/Madrid), whatever the server or
 * PHP default time zone is.
 *
 * - FechaHoraHusoGenRegistro: `Y-m-d\TH:i:sP` with the offset, e.g. `2026-07-09T16:00:00+02:00` (AEAT example
 *   `2024-01-01T19:20:30+01:00`).
 * - FechaExpedicionFactura: `d-m-Y`, the Madrid calendar day.
 *
 * Pass a PSR-20 clock (Symfony's `clock` service implements it) so tests can freeze time.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class GenerationTimestamp
{
    public const TIME_ZONE = 'Europe/Madrid';

    public const FORMAT = 'Y-m-d\TH:i:sP';

    public const ISSUE_DATE_FORMAT = 'd-m-Y';

    /**
     * Returns FechaHoraHusoGenRegistro for the clock's current time (the system time without a clock).
     */
    public static function now(?ClockInterface $clock = null): string
    {
        return self::format($clock?->now() ?? new DateTimeImmutable());
    }

    /**
     * Returns FechaHoraHusoGenRegistro for the given instant.
     */
    public static function format(DateTimeInterface $instant): string
    {
        return self::inMadrid($instant)->format(self::FORMAT);
    }

    /**
     * Returns FechaExpedicionFactura (dd-mm-yyyy) for the given instant, as a Madrid calendar day.
     */
    public static function issueDate(DateTimeInterface $instant): string
    {
        return self::inMadrid($instant)->format(self::ISSUE_DATE_FORMAT);
    }

    private static function inMadrid(DateTimeInterface $instant): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($instant)->setTimezone(new DateTimeZone(self::TIME_ZONE));
    }
}
