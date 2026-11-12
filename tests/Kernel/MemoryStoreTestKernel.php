<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Kernel;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

use function dirname;

/**
 * The integration test kernel with the in-memory record store enabled (`record_store.storage: memory`).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class MemoryStoreTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function getProjectDir(): string
    {
        return dirname(__DIR__) . '/Fixtures/app';
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        // Merged with the configuration of the shared test app (config/packages/nowo_verifactu.yaml).
        $container->loadFromExtension('nowo_verifactu', ['record_store' => ['storage' => 'memory']]);
    }
}
