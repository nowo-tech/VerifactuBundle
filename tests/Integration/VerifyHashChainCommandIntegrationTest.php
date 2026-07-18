<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Integration;

use Nowo\VerifactuBundle\Tests\Kernel\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class VerifyHashChainCommandIntegrationTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testVerifyHashCommandPassesWithAeatExampleVector(): void
    {
        self::bootKernel();
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $application = new Application($kernel);
        $command     = $application->find('nowo:verifactu:verify-hash');
        $tester      = new CommandTester($command);

        $exitCode = $tester->execute([
            '--nif'          => '89890001K',
            '--numserie'     => '12345678/G33',
            '--fecha'        => '01-01-2024',
            '--cuota'        => '12.35',
            '--importe'      => '123.45',
            '--generated-at' => '2024-01-01T19:20:30+01:00',
            '--hash'         => '3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Hash verification passed', $tester->getDisplay());
    }

    public function testVerifyHashCommandFailsWithWrongHash(): void
    {
        self::bootKernel();
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $application = new Application($kernel);
        $command     = $application->find('nowo:verifactu:verify-hash');
        $tester      = new CommandTester($command);

        self::assertSame(1, $tester->execute([
            '--nif'          => '89890001K',
            '--numserie'     => '12345678/G33',
            '--fecha'        => '01-01-2024',
            '--cuota'        => '12.35',
            '--importe'      => '123.45',
            '--generated-at' => '2024-01-01T19:20:30+01:00',
            '--hash'         => 'DEADBEEF',
        ]));
        self::assertStringContainsString('Hash verification failed', $tester->getDisplay());
    }
}
