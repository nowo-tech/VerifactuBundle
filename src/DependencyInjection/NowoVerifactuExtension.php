<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\DependencyInjection;

use Nowo\VerifactuBundle\Client\AeatSubmissionClientInterface;
use Nowo\VerifactuBundle\Client\NullAeatSubmissionClient;
use Nowo\VerifactuBundle\Client\SoapAeatSubmissionClient;
use Nowo\VerifactuBundle\Repository\DoctrineHashChainRepository;
use Nowo\VerifactuBundle\Repository\HashChainRepositoryInterface;
use Nowo\VerifactuBundle\Repository\InMemoryHashChainRepository;
use Nowo\VerifactuBundle\Signer\BillingRecordSignerInterface;
use Nowo\VerifactuBundle\Signer\XadesBillingRecordSigner;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
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
     * @param array<int, array<string, mixed>> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $processor     = new Processor();
        $configuration = new Configuration();
        $config        = $processor->processConfiguration($configuration, $configs);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        if (class_exists(DoctrineHashChainRepository::class) && $config['hash_chain']['storage'] === 'doctrine') {
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

        $this->configureHashChainRepository($container, $config);
        $this->configureSubmissionClient($container, $config);
        $this->configureSigner($container, $config);
    }

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

    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configureHashChainRepository(ContainerBuilder $container, array $config): void
    {
        if ($config['hash_chain']['repository'] !== null) {
            $container->setAlias('nowo_verifactu.hash_chain_repository', $config['hash_chain']['repository']);

            return;
        }

        if ($config['hash_chain']['storage'] === 'doctrine' && $container->hasDefinition(DoctrineHashChainRepository::class)) {
            $container->setAlias(HashChainRepositoryInterface::class, DoctrineHashChainRepository::class);
            $container->setAlias('nowo_verifactu.hash_chain_repository', DoctrineHashChainRepository::class);

            return;
        }

        $container->setAlias('nowo_verifactu.hash_chain_repository', InMemoryHashChainRepository::class);
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
}
