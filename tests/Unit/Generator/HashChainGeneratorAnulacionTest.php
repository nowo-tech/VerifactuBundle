<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Generator;

use Nowo\VerifactuBundle\Generator\HashChainGenerator;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use PHPUnit\Framework\TestCase;

use function strlen;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class HashChainGeneratorAnulacionTest extends TestCase
{
    public function testBuildAnulacionInputString(): void
    {
        $generator = new HashChainGenerator();
        $record    = new BillingRecord(
            RecordType::Anulacion,
            '89890001K',
            'FAC-2026-001',
            '09-07-2026',
            'F1',
            '0.00',
            '0.00',
            '2026-07-09T18:00:00+02:00',
        );

        self::assertStringContainsString('IDEmisorFactura=89890001K', $generator->buildInputString($record));
        self::assertSame(64, strlen($generator->computeHash($record)));
    }
}
