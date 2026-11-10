<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Keeps every log entry in memory.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
