<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Integration;

use Nowo\VerifactuBundle\Generator\HashChainGenerator;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Tests\Kernel\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration tests: kernel boots with the bundle and services are available.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class BundleIntegrationTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testKernelBoots(): void
    {
        self::bootKernel();
        self::assertTrue(self::getContainer()->has('kernel'));
    }

    public function testBundleServicesAreRegistered(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertTrue($container->has('nowo_verifactu.generator.hash_chain_generator'));
        self::assertTrue($container->has('nowo_verifactu.service.billing_record_processor'));

        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        self::assertTrue($application->has('nowo:verifactu:validate-record'));
        self::assertTrue($application->has('nowo:verifactu:verify-hash'));
    }

    public function testValidateRecordCommandRuns(): void
    {
        self::bootKernel();
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $command     = $application->find('nowo:verifactu:validate-record');
        $tester      = new CommandTester($command);
        $tester->execute([
            '--nif'          => '89890001K',
            '--numserie'     => 'FAC-001',
            '--fecha'        => '09-07-2026',
            '--cuota'        => '21.00',
            '--importe'      => '121.00',
            '--generated-at' => '2026-07-09T16:00:00+02:00',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Hash:', $tester->getDisplay());
    }

    public function testHashChainGeneratorServiceIsUsable(): void
    {
        self::bootKernel();
        $generator = self::getContainer()->get('nowo_verifactu.generator.hash_chain_generator');
        self::assertInstanceOf(HashChainGenerator::class, $generator);
    }

    public function testBillingRecordProcessorServiceIsUsable(): void
    {
        self::bootKernel();
        $processor = self::getContainer()->get('nowo_verifactu.service.billing_record_processor');
        self::assertInstanceOf(BillingRecordProcessor::class, $processor);
    }
}
