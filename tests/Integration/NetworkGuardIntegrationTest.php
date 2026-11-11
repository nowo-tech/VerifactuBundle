<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Integration;

use Nowo\VerifactuBundle\Client\CurlSoapTransport;
use Nowo\VerifactuBundle\Client\SoapTransportInterface;
use Nowo\VerifactuBundle\Tests\Kernel\DoctrineTestKernel;
use Nowo\VerifactuBundle\Tests\Kernel\TestKernel;
use Nowo\VerifactuBundle\Tests\Support\FakeSoapTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpKernel\Kernel;

/**
 * LJ-I4: no test reaches the AEAT. The test kernels wire the fake transport, and the real cURL transport refuses every
 * request while VERIFACTU_FORBID_NETWORK is set (phpunit.xml.dist and CI set it). Only an owner-run
 * `nowo:verifactu:submit-sandbox --submit`, outside the test suite, may send anything.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class NetworkGuardIntegrationTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<Kernel>}>
     */
    public static function kernels(): iterable
    {
        yield 'app' => [TestKernel::class];
        yield 'doctrine-app' => [DoctrineTestKernel::class];
    }

    /**
     * @param class-string<Kernel> $kernelClass
     */
    #[DataProvider('kernels')]
    public function testTheTestContainerUsesTheFakeTransport(string $kernelClass): void
    {
        $kernel = new $kernelClass('test', true);
        $kernel->boot();
        try {
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $container);
            self::assertInstanceOf(FakeSoapTransport::class, $container->get(SoapTransportInterface::class));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testTheSuiteForbidsTheNetwork(): void
    {
        self::assertTrue(CurlSoapTransport::isNetworkForbidden(), 'phpunit.xml.dist must set VERIFACTU_FORBID_NETWORK=1');

        $result = (new CurlSoapTransport())->send('https://192.0.2.1/soap', 'submit', '<xml/>', __FILE__, null, 5);
        self::assertSame(0, $result['status_code']);
        self::assertStringContainsString('VERIFACTU_FORBID_NETWORK', $result['error'] ?? '');
    }
}
