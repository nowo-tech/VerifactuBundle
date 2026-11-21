<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Kernel;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

use function dirname;

/**
 * DDD host that maps the bundle entities itself (`nowo_verifactu.doctrine.register_mapping: false`, 1.10): the shipped
 * XML files on a second entity manager ("verifactu", own connection), the host's XML-mapped entity on the default
 * one. The bundle registers no mapping, so the default manager never sees the bundle tables.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class HostMappedDoctrineTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function getProjectDir(): string
    {
        return dirname(__DIR__) . '/Fixtures/doctrine-app';
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/host_mapped_' . $this->environment;
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir() . '/var/log/host_mapped';
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->import($this->getProjectDir() . '/config/packages/framework.yaml');
        $container->extension('doctrine', [
            'dbal' => [
                'default_connection' => 'default',
                'connections'        => [
                    'default'   => ['driver' => 'pdo_sqlite', 'memory' => true],
                    'verifactu' => ['driver' => 'pdo_sqlite', 'memory' => true],
                ],
            ],
            'orm' => [
                'default_entity_manager' => 'default',
                'entity_managers'        => [
                    'default' => [
                        'connection' => 'default',
                        'mappings'   => ['HostXml' => [
                            'type'      => 'xml',
                            'is_bundle' => false,
                            'dir'       => dirname(__DIR__) . '/Fixtures/HostXmlEntity/mapping',
                            'prefix'    => 'Nowo\VerifactuBundle\Tests\Fixtures\HostXmlEntity',
                        ]],
                    ],
                    'verifactu' => [
                        'connection' => 'verifactu',
                        'mappings'   => ['NowoVerifactu' => [
                            'type'      => 'xml',
                            'is_bundle' => false,
                            'dir'       => dirname(__DIR__, 2) . '/src/Resources/config/doctrine',
                            'prefix'    => 'Nowo\VerifactuBundle\Entity',
                        ]],
                    ],
                ],
            ],
        ]);
        $container->extension('nowo_verifactu', [
            'issuer'       => ['nif' => '89890001K', 'name' => 'Doctrine Test Issuer'],
            'software'     => ['manufacturer_nif' => '89890001K', 'manufacturer_name' => 'Nowo.tech', 'name' => 'VerifactuBundle', 'id' => '01', 'version' => '1.0.0'],
            'hash_chain'   => ['storage' => 'doctrine'],
            'record_store' => ['storage' => 'doctrine'],
            'doctrine'     => ['register_mapping' => false],
        ]);
    }
}
