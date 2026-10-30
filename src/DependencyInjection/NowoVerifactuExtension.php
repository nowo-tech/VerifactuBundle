<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use LogicException;
use Nowo\VerifactuBundle\Client\AeatSubmissionClientInterface;
use Nowo\VerifactuBundle\Client\NullAeatSubmissionClient;
use Nowo\VerifactuBundle\Client\SoapAeatSubmissionClient;
use Nowo\VerifactuBundle\Doctrine\TablePrefixListener;
use Nowo\VerifactuBundle\Repository\DoctrineHashChainRepository;
use Nowo\VerifactuBundle\Repository\HashChainRepositoryInterface;
use Nowo\VerifactuBundle\Repository\InMemoryHashChainRepository;
use Nowo\VerifactuBundle\Signer\BillingRecordSignerInterface;
use Nowo\VerifactuBundle\Signer\XadesBillingRecordSigner;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Loads Veri*Factu bundle services and publishes configuration parameters.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class NowoVerifactuExtension extends Extension implements PrependExtensionInterface
{
    /**
     * Loads the services and exposes the processed configuration as container parameters.
     *
     * @param array<int, array<string, mixed>> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $processor     = new Processor();
        $configuration = new Configuration();
        $config        = $processor->processConfiguration($configuration, $configs);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        // Check storage first and probe the Doctrine parent class by name: autoloading
        // DoctrineHashChainRepository itself fails hard when doctrine/doctrine-bundle is not installed.
        if ($config['hash_chain']['storage'] === 'doctrine') {
            if (!class_exists(ServiceEntityRepository::class)) {
                throw new LogicException('nowo_verifactu.hash_chain.storage "doctrine" requires doctrine/doctrine-bundle and doctrine/orm. Install them (composer require doctrine/doctrine-bundle doctrine/orm) or configure hash_chain.repository with your own persistent repository.');
            }

            $loader->load('services_doctrine.yaml');
        }

        $container->setParameter('nowo_verifactu.mode', $config['mode']);
        $container->setParameter('nowo_verifactu.aeat_spec_version', $config['aeat_spec_version']);
        $container->setParameter('nowo_verifactu.issuer', $config['issuer']);
        $container->setParameter('nowo_verifactu.software', $config['software']);
        $container->setParameter('nowo_verifactu.installation', $config['installation']);
        $container->setParameter('nowo_verifactu.qr', $config['qr']);
        $container->setParameter('nowo_verifactu.aeat.environment', $config['aeat']['environment']);
        $container->setParameter('nowo_verifactu.aeat.certificate_path', $config['aeat']['certificate_path']);
        $container->setParameter('nowo_verifactu.aeat.certificate_password', $config['aeat']['certificate_password']);
        $container->setParameter('nowo_verifactu.aeat.certificate_type', $config['aeat']['certificate_type']);
        $container->setParameter('nowo_verifactu.aeat.timeout', $config['aeat']['timeout']);
        $container->setParameter('nowo_verifactu.aeat.validate_xsd', $config['aeat']['validate_xsd']);
        $container->setParameter('nowo_verifactu.aeat.sign_xades', $config['aeat']['sign_xades']);
        $container->setParameter('nowo_verifactu.doctrine.table_prefix', $config['doctrine']['table_prefix']);

        $this->assertPersistentChainForProduction($config);
        $this->configureHashChainRepository($container, $config);
        $this->configureSubmissionClient($container, $config);
        $this->configureSigner($container, $config);
        $this->registerTablePrefixListener($container);
    }

    /**
     * Registers the Doctrine attribute mapping of the bundle entities when DoctrineBundle is enabled.
     */
    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('doctrine')) {
            return;
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'NowoVerifactuBundle' => [
                        'is_bundle' => true,
                        'type'      => 'attribute',
                        'dir'       => 'Entity',
                        'prefix'    => 'Nowo\\VerifactuBundle\\Entity',
                        'alias'     => 'NowoVerifactu',
                    ],
                ],
            ],
        ]);
    }

    /**
     * Returns the configuration alias `nowo_verifactu`.
     */
    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /**
     * Production submissions chain each record onto the previous one: with the request-scoped memory storage every
     * request would start a new chain, so a production AEAT client without persistent storage is refused here.
     *
     * @param array<string, mixed> $config
     */
    private function assertPersistentChainForProduction(array $config): void
    {
        $submitsToAeat = $config['aeat']['submission_client'] !== null
            || ($config['aeat']['certificate_path'] !== null && $config['aeat']['certificate_path'] !== '');

        if ($config['aeat']['environment'] === 'production'
            && $submitsToAeat
            && $config['hash_chain']['repository'] === null
            && $config['hash_chain']['storage'] === 'memory'
        ) {
            throw new LogicException('nowo_verifactu.aeat.environment "production" submits records to the AEAT, but nowo_verifactu.hash_chain.storage is "memory" (request-scoped: every request would start a new hash chain). Set hash_chain.storage: doctrine or a persistent hash_chain.repository.');
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configureHashChainRepository(ContainerBuilder $container, array $config): void
    {
        if ($config['hash_chain']['repository'] !== null) {
            $container->setAlias('nowo_verifactu.hash_chain_repository', $config['hash_chain']['repository']);
            $container->setAlias(HashChainRepositoryInterface::class, $config['hash_chain']['repository']);

            return;
        }

        if ($config['hash_chain']['storage'] === 'doctrine' && $container->hasDefinition(DoctrineHashChainRepository::class)) {
            $container->setAlias(HashChainRepositoryInterface::class, DoctrineHashChainRepository::class);
            $container->setAlias('nowo_verifactu.hash_chain_repository', DoctrineHashChainRepository::class);

            return;
        }

        $container->setAlias('nowo_verifactu.hash_chain_repository', InMemoryHashChainRepository::class);
        $container->getDefinition(InMemoryHashChainRepository::class)
            ->setArgument('$warnNotPersistent', $container->hasParameter('kernel.debug') && $container->getParameter('kernel.debug') === false);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configureSubmissionClient(ContainerBuilder $container, array $config): void
    {
        if ($config['aeat']['submission_client'] !== null) {
            $container->setAlias('nowo_verifactu.aeat_submission_client', $config['aeat']['submission_client']);
            $container->setAlias(AeatSubmissionClientInterface::class, $config['aeat']['submission_client']);

            return;
        }

        if ($config['aeat']['certificate_path'] !== null && $config['aeat']['certificate_path'] !== '') {
            $container->setAlias(AeatSubmissionClientInterface::class, SoapAeatSubmissionClient::class);
            $container->setAlias('nowo_verifactu.aeat_submission_client', SoapAeatSubmissionClient::class);

            return;
        }

        $container->setAlias('nowo_verifactu.aeat_submission_client', NullAeatSubmissionClient::class);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configureSigner(ContainerBuilder $container, array $config): void
    {
        if (($config['aeat']['sign_xades'] || $config['mode'] === 'no_verifactu')
            && $config['aeat']['certificate_path'] !== null
            && $config['aeat']['certificate_path'] !== ''
        ) {
            $container->setAlias(BillingRecordSignerInterface::class, XadesBillingRecordSigner::class);
        }
    }

    /**
     * Applies doctrine.table_prefix to the bundle entity metadata (REQ-ORM-002); no-op while the prefix is empty.
     * Without DoctrineBundle the `doctrine.event_listener` tag is never collected and the private service is removed.
     */
    private function registerTablePrefixListener(ContainerBuilder $container): void
    {
        $container->setDefinition(TablePrefixListener::class, (new Definition(TablePrefixListener::class))
            ->setArgument('$tablePrefix', '%nowo_verifactu.doctrine.table_prefix%')
            ->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata'])
            ->setPublic(false));
    }
}
