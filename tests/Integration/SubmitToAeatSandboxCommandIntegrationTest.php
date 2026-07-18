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
final class SubmitToAeatSandboxCommandIntegrationTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testDryRunGeneratesHashWithoutSubmittingToAeat(): void
    {
        self::bootKernel();
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $application = new Application($kernel);
        $command     = $application->find('nowo:verifactu:submit-sandbox');
        $tester      = new CommandTester($command);

        $exitCode = $tester->execute([
            '--fecha'        => '09-07-2026',
            '--generated-at' => '2026-07-09T16:00:00+02:00',
            '--dry-run'      => true,
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Hash:', $tester->getDisplay());
        self::assertStringContainsString('Dry run', $tester->getDisplay());
    }

    public function testSubmitRequiresConfiguredCertificateInNonTestEnvironments(): void
    {
        self::bootKernel(['environment' => 'dev']);
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $application = new Application($kernel);
        $command     = $application->find('nowo:verifactu:submit-sandbox');
        $tester      = new CommandTester($command);

        self::assertSame(1, $tester->execute(['--submit' => true]));
        self::assertStringContainsString('certificate is not configured', $tester->getDisplay());
    }

    public function testInvalidRecordTypeFails(): void
    {
        self::bootKernel();
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $application = new Application($kernel);
        $command     = $application->find('nowo:verifactu:submit-sandbox');
        $tester      = new CommandTester($command);

        self::assertSame(2, $tester->execute(['--record-type' => 'Invalid']));
        self::assertStringContainsString('Invalid record type', $tester->getDisplay());
    }

    public function testDryRunWithAnulacionRecordType(): void
    {
        self::bootKernel();
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $application = new Application($kernel);
        $command     = $application->find('nowo:verifactu:submit-sandbox');
        $tester      = new CommandTester($command);

        self::assertSame(0, $tester->execute([
            '--record-type'  => 'Anulacion',
            '--fecha'        => '09-07-2026',
            '--generated-at' => '2026-07-09T16:00:00+02:00',
            '--dry-run'      => true,
        ]));
        self::assertStringContainsString('Dry run', $tester->getDisplay());
    }

    public function testValidationFailureIsReported(): void
    {
        self::bootKernel();
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $application = new Application($kernel);
        $command     = $application->find('nowo:verifactu:submit-sandbox');
        $tester      = new CommandTester($command);

        self::assertSame(1, $tester->execute([
            '--nif'     => 'INVALID',
            '--dry-run' => true,
        ]));
        self::assertStringContainsString('Validation failed', $tester->getDisplay());
    }

    public function testSubmitWithConfiguredCertificateReturnsAeatReference(): void
    {
        self::bootKernel();
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $application = new Application($kernel);
        $command     = $application->find('nowo:verifactu:submit-sandbox');
        $tester      = new CommandTester($command);

        self::assertSame(0, $tester->execute([
            '--fecha'        => '09-07-2026',
            '--generated-at' => '2026-07-09T16:00:00+02:00',
            '--submit'       => true,
        ]));
        self::assertStringContainsString('TESTCSV', $tester->getDisplay());
        self::assertStringContainsString('AEAT submission OK', $tester->getDisplay());
    }
}
