<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Command;

use Nowo\VerifactuBundle\Command\VerifyHashChainCommand;
use Nowo\VerifactuBundle\Generator\HashChainGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class VerifyHashChainCommandTest extends TestCase
{
    public function testExecuteReturnsFailureWhenHashDoesNotMatch(): void
    {
        $tester = new CommandTester(new VerifyHashChainCommand(new HashChainGenerator()));

        $exitCode = $tester->execute([
            '--nif'          => '89890001K',
            '--numserie'     => '12345678/G33',
            '--fecha'        => '01-01-2024',
            '--cuota'        => '12.35',
            '--importe'      => '123.45',
            '--generated-at' => '2024-01-01T19:20:30+01:00',
            '--hash'         => 'DEADBEEF',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Hash verification failed', $tester->getDisplay());
        self::assertStringContainsString('Computed:', $tester->getDisplay());
    }
}
