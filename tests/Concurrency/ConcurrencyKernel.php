<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Concurrency;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

use function dirname;

/**
 * Doctrine kernel on a server database (VERIFACTU_TEST_DATABASE_URL), with the record store on or off
 * (VERIFACTU_TEST_RECORD_STORE = doctrine | none). Used by the multi-process chain tests.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class ConcurrencyKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param string $recordStore doctrine | none; '' reads VERIFACTU_TEST_RECORD_STORE (worker processes)
     */
    public function __construct(string $environment, bool $debug, private readonly string $recordStore = '')
    {
        parent::__construct($environment, $debug);
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__) . '/Fixtures/doctrine-app';
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/verifactu-concurrency/' . md5(self::databaseUrl() . $this->recordStore()) . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/verifactu-concurrency/log';
    }

    public static function databaseUrl(): string
    {
        return (string) getenv('VERIFACTU_TEST_DATABASE_URL');
    }

    public function recordStore(): string
    {
        $store = $this->recordStore !== '' ? $this->recordStore : (string) getenv('VERIFACTU_TEST_RECORD_STORE');

        return $store === 'none' ? 'none' : 'doctrine';
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->import($this->getProjectDir() . '/config/{packages}/*.yaml');
        $container->import($this->getProjectDir() . '/config/{packages}/' . $this->environment . '/*.yaml');
        $container->extension('doctrine', ['dbal' => ['url' => self::databaseUrl()]]);
        $container->extension('nowo_verifactu', ['record_store' => ['storage' => $this->recordStore()]]);
    }
}
