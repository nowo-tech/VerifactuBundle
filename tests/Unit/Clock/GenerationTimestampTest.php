<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Clock;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\VerifactuBundle\Clock\GenerationTimestamp;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class GenerationTimestampTest extends TestCase
{
    public function testFormatsInMadridWhateverTheInputTimeZone(): void
    {
        $utc = new DateTimeImmutable('2024-01-01T18:20:30', new DateTimeZone('UTC'));

        self::assertSame('2024-01-01T19:20:30+01:00', GenerationTimestamp::format($utc));
        self::assertSame('2026-07-09T16:00:00+02:00', GenerationTimestamp::format(new DateTimeImmutable('2026-07-09T10:00:00-04:00')));
    }

    public function testIssueDateIsTheMadridCalendarDay(): void
    {
        // 23:30 UTC on 31 December is already 1 January in Madrid
        self::assertSame('01-01-2025', GenerationTimestamp::issueDate(new DateTimeImmutable('2024-12-31T23:30:00', new DateTimeZone('UTC'))));
    }

    public function testNowUsesThePsr20Clock(): void
    {
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-03-29T00:59:59', new DateTimeZone('UTC'));
            }
        };

        self::assertSame('2026-03-29T01:59:59+01:00', GenerationTimestamp::now($clock));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+0[12]:00$/', GenerationTimestamp::now());
    }
}
