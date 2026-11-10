<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Kernel;

use Nowo\VerifactuBundle\Tests\Support\DatabaseBackedIdentityProvider;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

use function dirname;

/**
 * Doctrine test kernel of a DDD host: the bundle entities are mapped from XML (`doctrine.mapping: xml`) and the host
 * entity is a plain-PHP class mapped in XML. The issuer and the software identity come from a database-backed provider.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class XmlMappingDoctrineTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function getProjectDir(): string
    {
        return dirname(__DIR__) . '/Fixtures/doctrine-app';
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/xml_' . $this->environment;
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir() . '/var/log/xml';
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->register(DatabaseBackedIdentityProvider::class)->setPublic(true);
        $container->loadFromExtension('nowo_verifactu', [
            'doctrine'  => ['mapping' => 'xml'],
            'providers' => ['issuer' => DatabaseBackedIdentityProvider::class, 'software_identity' => DatabaseBackedIdentityProvider::class],
        ]);
        $container->loadFromExtension('doctrine', ['orm' => ['mappings' => ['HostXml' => [
            'type'      => 'xml',
            'is_bundle' => false,
            'dir'       => dirname(__DIR__) . '/Fixtures/HostXmlEntity/mapping',
            'prefix'    => 'Nowo\VerifactuBundle\Tests\Fixtures\HostXmlEntity',
        ]]]]);
    }
}
