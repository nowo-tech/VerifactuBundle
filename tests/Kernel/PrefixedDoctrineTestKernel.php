<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Kernel;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

use function dirname;

/**
 * Doctrine test kernel with `nowo_verifactu.doctrine.table_prefix: app_` (REQ-ORM-002).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class PrefixedDoctrineTestKernel extends Kernel
{
    use MicroKernelTrait;

    public const TABLE_PREFIX = 'app_';

    public function getProjectDir(): string
    {
        return dirname(__DIR__) . '/Fixtures/doctrine-app';
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/prefixed_' . $this->environment;
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir() . '/var/log/prefixed';
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->loadFromExtension('nowo_verifactu', ['doctrine' => ['table_prefix' => self::TABLE_PREFIX]]);
    }
}
