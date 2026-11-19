<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Concurrency;

use Nowo\VerifactuBundle\Client\NullAeatSubmissionClient;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

use function dirname;

/**
 * Doctrine kernel on a server database (VERIFACTU_TEST_DATABASE_URL), with the record store on or off
 * (VERIFACTU_TEST_RECORD_STORE = doctrine | none). Used by the multi-process chain tests. Every session waits at most
 * LOCK_WAIT_SECONDS for a row lock (MySQL / MariaDB innodb_lock_wait_timeout, PostgreSQL lock_timeout).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class ConcurrencyKernel extends Kernel
{
    use MicroKernelTrait;

    /** Lock wait bound of every test session: a hang fails fast (lock-wait error) instead of holding the machine. */
    public const LOCK_WAIT_SECONDS = 15;

    /**
     * @param string $recordStore doctrine | none; '' reads VERIFACTU_TEST_RECORD_STORE (worker processes)
     */
    public function __construct(string $environment, bool $debug, private readonly string $recordStore = '')
    {
        parent::__construct($environment, $debug);
        if (str_starts_with(self::databaseUrl(), 'pgsql')) {
            // libpq reads PGOPTIONS when it connects (this process and the worker processes it starts).
            // @phpstan-ignore frankenphp.classic.noPutenv (CLI test kernel, never a worker; libpq reads only the environment)
            putenv('PGOPTIONS=-c lock_timeout=' . self::LOCK_WAIT_SECONDS . 's');
        }
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

    /**
     * A valid DNI per issuer number (the single-issuer scenarios keep the AEAT example NIF).
     */
    public static function issuerNif(int $issuer, int $issuers): string
    {
        if ($issuers === 1) {
            return '89890001K';
        }
        $number = 10000000 + $issuer;

        return $number . 'TRWAGMYFPDXBNJZSQVHLCKE'[$number % 23];
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
        $dbal = ['url' => self::databaseUrl()];
        if (str_starts_with(self::databaseUrl(), 'mysql')) {
            // 1002 = PDO MySQL ATTR_INIT_COMMAND (the constant moved to Pdo\Mysql in PHP 8.4).
            $dbal['options'] = [1002 => 'SET SESSION innodb_lock_wait_timeout = ' . self::LOCK_WAIT_SECONDS];
        }
        $container->extension('doctrine', ['dbal' => $dbal]);
        // Never the SOAP client of the test configuration: a submitToAeat test must not reach the network.
        $container->extension('nowo_verifactu', [
            'record_store' => ['storage' => $this->recordStore()],
            'aeat'         => ['submission_client' => NullAeatSubmissionClient::class],
        ]);
    }
}
