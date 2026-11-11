<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\DependencyInjection;

use ArrayObject;
use LogicException;
use Nowo\VerifactuBundle\Certificate\CertificateProviderInterface;
use Nowo\VerifactuBundle\Certificate\ConfigCertificateProvider;
use Nowo\VerifactuBundle\Client\AeatSubmissionClientInterface;
use Nowo\VerifactuBundle\Client\NullAeatSubmissionClient;
use Nowo\VerifactuBundle\Client\SoapAeatSubmissionClient;
use Nowo\VerifactuBundle\Command\VerifyChainCommand;
use Nowo\VerifactuBundle\DependencyInjection\NowoVerifactuExtension;
use Nowo\VerifactuBundle\Doctrine\TablePrefixListener;
use Nowo\VerifactuBundle\EventSubscriber\WorkerStateResetSubscriber;
use Nowo\VerifactuBundle\Provider\AeatEnvironmentProviderInterface;
use Nowo\VerifactuBundle\Provider\ConfigAeatEnvironmentProvider;
use Nowo\VerifactuBundle\Provider\ConfigIssuerProvider;
use Nowo\VerifactuBundle\Provider\ConfigSoftwareIdentityProvider;
use Nowo\VerifactuBundle\Provider\IssuerProviderInterface;
use Nowo\VerifactuBundle\Provider\SoftwareIdentityProviderInterface;
use Nowo\VerifactuBundle\Repository\BillingRecordStoreInterface;
use Nowo\VerifactuBundle\Repository\DoctrineBillingRecordStore;
use Nowo\VerifactuBundle\Repository\DoctrineHashChainRepository;
use Nowo\VerifactuBundle\Repository\HashChainRepositoryInterface;
use Nowo\VerifactuBundle\Repository\InMemoryBillingRecordStore;
use Nowo\VerifactuBundle\Repository\InMemoryHashChainRepository;
use Nowo\VerifactuBundle\Service\ChainVerifier;
use Nowo\VerifactuBundle\Signer\BillingRecordSignerInterface;
use Nowo\VerifactuBundle\Signer\XadesBillingRecordSigner;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class NowoVerifactuExtensionTest extends TestCase
{
    public function testLoadSetsParameters(): void
    {
        $container = new ContainerBuilder();
        $extension = new NowoVerifactuExtension();

        $extension->load([
            [
                'mode'   => 'verifactu',
                'issuer' => ['nif' => '89890001K', 'name' => 'Demo'],
            ],
        ], $container);

        self::assertSame('verifactu', $container->getParameter('nowo_verifactu.mode'));
        /** @var array{nif: string, name: string} $issuer */
        $issuer = $container->getParameter('nowo_verifactu.issuer');
        self::assertSame('89890001K', $issuer['nif']);
        self::assertTrue($container->hasDefinition('Nowo\\VerifactuBundle\\Generator\\HashChainGenerator'));
    }

    /**
     * Simulates an application without doctrine/doctrine-bundle: the default (memory) storage must
     * build without ever autoloading the Doctrine-based repository or its Doctrine parent class.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMemoryStorageBuildsWithoutDoctrineInstalled(): void
    {
        $requested = $this->hideDoctrineBundleFromAutoloader();

        $container = new ContainerBuilder();
        (new NowoVerifactuExtension())->load([[]], $container);

        self::assertSame(InMemoryHashChainRepository::class, (string) $container->getAlias('nowo_verifactu.hash_chain_repository'));
        self::assertNotContains(DoctrineHashChainRepository::class, $requested->getArrayCopy());
        self::assertFalse(class_exists(DoctrineHashChainRepository::class, false));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDoctrineStorageWithoutDoctrineInstalledFailsWithClearMessage(): void
    {
        $this->hideDoctrineBundleFromAutoloader();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires doctrine/doctrine-bundle');

        (new NowoVerifactuExtension())->load([['hash_chain' => ['storage' => 'doctrine']]], new ContainerBuilder());
    }

    public function testTablePrefixDefaultsToEmptyAndRegistersTheMetadataListener(): void
    {
        $container = new ContainerBuilder();
        (new NowoVerifactuExtension())->load([[]], $container);

        self::assertSame('', $container->getParameter('nowo_verifactu.doctrine.table_prefix'));

        $definition = $container->getDefinition(TablePrefixListener::class);
        self::assertSame('%nowo_verifactu.doctrine.table_prefix%', $definition->getArgument('$tablePrefix'));
        self::assertSame([['event' => 'loadClassMetadata']], $definition->getTag('doctrine.event_listener'));
        self::assertFalse($definition->isPublic());
    }

    public function testTablePrefixIsExposedAsParameter(): void
    {
        $container = new ContainerBuilder();
        (new NowoVerifactuExtension())->load([['doctrine' => ['table_prefix' => 'app_']]], $container);

        self::assertSame('app_', $container->getParameter('nowo_verifactu.doctrine.table_prefix'));
    }

    public function testProductionSubmissionWithMemoryStorageIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('hash_chain.storage is "memory"');

        (new NowoVerifactuExtension())->load([[
            'aeat' => ['environment' => 'production', 'certificate_path' => '/run/secrets/aeat.p12'],
        ]], new ContainerBuilder());
    }

    public function testProductionCustomSubmissionClientWithMemoryStorageIsRefused(): void
    {
        $this->expectException(LogicException::class);

        (new NowoVerifactuExtension())->load([[
            'aeat' => ['environment' => 'production', 'submission_client' => 'app.aeat_client'],
        ]], new ContainerBuilder());
    }

    public function testProductionIsAcceptedWithCustomRepositoryOrWithoutSubmission(): void
    {
        $withRepository = new ContainerBuilder();
        (new NowoVerifactuExtension())->load([[
            'aeat'       => ['environment' => 'production', 'certificate_path' => '/run/secrets/aeat.p12'],
            'hash_chain' => ['repository' => 'app.hash_chain'],
        ]], $withRepository);
        self::assertSame('app.hash_chain', (string) $withRepository->getAlias(HashChainRepositoryInterface::class));

        $withoutCertificate = new ContainerBuilder();
        (new NowoVerifactuExtension())->load([['aeat' => ['environment' => 'production']]], $withoutCertificate);
        self::assertSame(InMemoryHashChainRepository::class, (string) $withoutCertificate->getAlias('nowo_verifactu.hash_chain_repository'));
    }

    public function testGetAlias(): void
    {
        self::assertSame('nowo_verifactu', (new NowoVerifactuExtension())->getAlias());
    }

    public function testPrependDoctrineMappingsWhenDoctrineExtensionIsRegistered(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'doctrine';
            }
        });

        (new NowoVerifactuExtension())->prepend($container);

        self::assertNotSame([], $container->getExtensionConfig('doctrine'));
    }

    public function testPrependDoesNothingWithoutDoctrineExtension(): void
    {
        $container = new ContainerBuilder();

        (new NowoVerifactuExtension())->prepend($container);

        self::assertFalse($container->hasExtension('doctrine'));
    }

    public function testCustomHashChainRepositoryAlias(): void
    {
        $container = new ContainerBuilder();
        $container->register('app.custom_hash_chain')->setPublic(true);

        (new NowoVerifactuExtension())->load([
            [
                'hash_chain' => [
                    'repository' => 'app.custom_hash_chain',
                    'storage'    => 'memory',
                ],
            ],
        ], $container);

        self::assertSame('app.custom_hash_chain', (string) $container->getAlias('nowo_verifactu.hash_chain_repository'));
        self::assertSame('app.custom_hash_chain', (string) $container->getAlias(HashChainRepositoryInterface::class));
    }

    public function testInMemoryRepositoryIsResetBetweenRequestsAndWarnsOutsideDebug(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);

        (new NowoVerifactuExtension())->load([[]], $container);

        $definition = $container->getDefinition(InMemoryHashChainRepository::class);
        self::assertTrue($definition->getArgument('$warnNotPersistent'));
        self::assertSame([['method' => 'clear']], $definition->getTag('kernel.reset'));
        self::assertTrue($container->hasDefinition(WorkerStateResetSubscriber::class));
    }

    public function testInMemoryRepositoryDoesNotWarnInDebug(): void
    {
        foreach ([true, null] as $debug) {
            $container = new ContainerBuilder();
            if ($debug !== null) {
                $container->setParameter('kernel.debug', $debug);
            }

            (new NowoVerifactuExtension())->load([[]], $container);

            self::assertFalse($container->getDefinition(InMemoryHashChainRepository::class)->getArgument('$warnNotPersistent'));
        }
    }

    public function testDoctrineHashChainRepositoryAliasWhenDoctrineStorageIsEnabled(): void
    {
        if (!class_exists(DoctrineHashChainRepository::class)) {
            self::markTestSkipped('Doctrine is not available.');
        }

        $container = new ContainerBuilder();

        (new NowoVerifactuExtension())->load([
            [
                'hash_chain' => [
                    'storage' => 'doctrine',
                ],
            ],
        ], $container);

        self::assertTrue($container->hasDefinition(DoctrineHashChainRepository::class));
        self::assertSame(
            DoctrineHashChainRepository::class,
            (string) $container->getAlias(HashChainRepositoryInterface::class),
        );
    }

    public function testInMemoryHashChainRepositoryIsDefault(): void
    {
        $container = new ContainerBuilder();

        (new NowoVerifactuExtension())->load([[]], $container);

        self::assertSame(
            InMemoryHashChainRepository::class,
            (string) $container->getAlias('nowo_verifactu.hash_chain_repository'),
        );
    }

    public function testSoapSubmissionClientAliasWhenCertificateIsConfigured(): void
    {
        $container = new ContainerBuilder();

        (new NowoVerifactuExtension())->load([
            [
                'aeat' => [
                    'certificate_path' => '/tmp/test.p12',
                ],
            ],
        ], $container);

        self::assertSame(
            SoapAeatSubmissionClient::class,
            (string) $container->getAlias(AeatSubmissionClientInterface::class),
        );
    }

    public function testNullSubmissionClientWhenCertificateIsMissing(): void
    {
        $container = new ContainerBuilder();

        (new NowoVerifactuExtension())->load([[]], $container);

        self::assertSame(
            NullAeatSubmissionClient::class,
            (string) $container->getAlias('nowo_verifactu.aeat_submission_client'),
        );
    }

    public function testCustomSubmissionClientAlias(): void
    {
        $container = new ContainerBuilder();
        $container->register('app.custom_submission_client')->setPublic(true);

        (new NowoVerifactuExtension())->load([
            [
                'aeat' => [
                    'submission_client' => 'app.custom_submission_client',
                ],
            ],
        ], $container);

        self::assertSame(
            'app.custom_submission_client',
            (string) $container->getAlias(AeatSubmissionClientInterface::class),
        );
    }

    public function testXadesSignerAliasWhenSigningIsRequired(): void
    {
        $container = new ContainerBuilder();

        (new NowoVerifactuExtension())->load([
            [
                'mode' => 'no_verifactu',
                'aeat' => [
                    'certificate_path' => '/tmp/test.p12',
                    'sign_xades'       => false,
                ],
            ],
        ], $container);

        self::assertSame(
            XadesBillingRecordSigner::class,
            (string) $container->getAlias(BillingRecordSignerInterface::class),
        );
    }

    public function testXadesSignerAliasWhenSignXadesIsEnabled(): void
    {
        $container = new ContainerBuilder();

        (new NowoVerifactuExtension())->load([
            [
                'mode' => 'verifactu',
                'aeat' => [
                    'certificate_path' => '/tmp/test.p12',
                    'sign_xades'       => true,
                ],
            ],
        ], $container);

        self::assertSame(
            XadesBillingRecordSigner::class,
            (string) $container->getAlias(BillingRecordSignerInterface::class),
        );
    }

    /**
     * Replaces the registered autoloaders with a wrapper that cannot resolve DoctrineBundle classes
     * (nor the bundle's Doctrine repository), and records every class requested from it.
     *
     * @return ArrayObject<int, string>
     */
    private function hideDoctrineBundleFromAutoloader(): ArrayObject
    {
        /** @var ArrayObject<int, string> $requested */
        $requested = new ArrayObject();
        $loaders   = spl_autoload_functions();
        foreach ($loaders as $loader) {
            spl_autoload_unregister($loader);
        }

        spl_autoload_register(static function (string $class) use ($loaders, $requested): void {
            $requested->append($class);
            if (str_starts_with($class, 'Doctrine\\Bundle\\DoctrineBundle\\') || $class === DoctrineHashChainRepository::class) {
                return;
            }

            foreach ($loaders as $loader) {
                $loader($class);
                if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false) || enum_exists($class, false)) {
                    return;
                }
            }
        });

        return $requested;
    }

    public function testProvidersDefaultToTheStaticConfiguration(): void
    {
        $container = new ContainerBuilder();
        (new NowoVerifactuExtension())->load([[]], $container);

        self::assertSame(ConfigIssuerProvider::class, (string) $container->getAlias(IssuerProviderInterface::class));
        self::assertSame(ConfigSoftwareIdentityProvider::class, (string) $container->getAlias(SoftwareIdentityProviderInterface::class));
    }

    public function testProvidersCanPointAtHostServices(): void
    {
        $container = new ContainerBuilder();
        (new NowoVerifactuExtension())->load([['providers' => ['issuer' => 'app.issuer', 'software_identity' => 'app.software']]], $container);

        self::assertSame('app.issuer', (string) $container->getAlias(IssuerProviderInterface::class));
        self::assertSame('app.software', (string) $container->getAlias(SoftwareIdentityProviderInterface::class));
    }

    public function testIssuerEnforcementFollowsTheIssuerProviderAndCanBeTurnedOff(): void
    {
        $static = new ContainerBuilder();
        (new NowoVerifactuExtension())->load([[]], $static);
        self::assertFalse($static->getParameter('nowo_verifactu.providers.issuer_enforced'));

        $provider = new ContainerBuilder();
        (new NowoVerifactuExtension())->load([['providers' => ['issuer' => 'app.issuer']]], $provider);
        self::assertTrue($provider->getParameter('nowo_verifactu.providers.issuer_enforced'));

        $optOut = new ContainerBuilder();
        (new NowoVerifactuExtension())->load([['providers' => ['issuer' => 'app.issuer', 'issuer_enforced' => false]]], $optOut);
        self::assertFalse($optOut->getParameter('nowo_verifactu.providers.issuer_enforced'));
    }

    public function testWithoutRecordStoreTheChainVerifierIsRemoved(): void
    {
        $container = new ContainerBuilder();
        (new NowoVerifactuExtension())->load([[]], $container);

        self::assertFalse($container->hasDefinition(ChainVerifier::class));
        self::assertFalse($container->hasDefinition(VerifyChainCommand::class));
        self::assertFalse($container->hasAlias(ChainVerifier::SERVICE_NAME));
        self::assertFalse($container->hasAlias(BillingRecordStoreInterface::class));
    }

    public function testMemoryRecordStore(): void
    {
        $container = new ContainerBuilder();
        (new NowoVerifactuExtension())->load([['record_store' => ['storage' => 'memory']]], $container);

        self::assertSame(InMemoryBillingRecordStore::class, (string) $container->getAlias(BillingRecordStoreInterface::class));
        self::assertSame(InMemoryBillingRecordStore::class, (string) $container->getAlias('nowo_verifactu.billing_record_store'));
        self::assertTrue($container->hasDefinition(ChainVerifier::class));
        self::assertSame([['method' => 'clear']], $container->getDefinition(InMemoryBillingRecordStore::class)->getTag('kernel.reset'));
    }

    public function testDoctrineRecordStore(): void
    {
        $container = new ContainerBuilder();
        (new NowoVerifactuExtension())->load([['record_store' => ['storage' => 'doctrine']]], $container);

        self::assertSame(DoctrineBillingRecordStore::class, (string) $container->getAlias(BillingRecordStoreInterface::class));
        self::assertFalse($container->getDefinition(DoctrineBillingRecordStore::class)->hasTag('container.excluded'));
        self::assertSame(DoctrineBillingRecordStore::class, (string) $container->getAlias(DoctrineBillingRecordStore::SERVICE_NAME));
    }

    public function testCustomRecordStoreServiceWins(): void
    {
        $container = new ContainerBuilder();
        (new NowoVerifactuExtension())->load([['record_store' => ['storage' => 'doctrine', 'service' => 'app.record_store']]], $container);

        self::assertSame('app.record_store', (string) $container->getAlias(BillingRecordStoreInterface::class));
        self::assertFalse($container->hasAlias(DoctrineBillingRecordStore::SERVICE_NAME));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDoctrineRecordStoreWithoutDoctrineInstalledFailsWithClearMessage(): void
    {
        $this->hideDoctrineBundleFromAutoloader();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('record_store.storage "doctrine" requires doctrine/doctrine-bundle');

        (new NowoVerifactuExtension())->load([['record_store' => ['storage' => 'doctrine']]], new ContainerBuilder());
    }

    public function testPrependUsesTheXmlMappingWhenRequested(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'doctrine';
            }
        });
        $container->registerExtension(new NowoVerifactuExtension());
        $container->prependExtensionConfig('nowo_verifactu', ['doctrine' => ['mapping' => 'xml']]);
        $container->prependExtensionConfig('nowo_verifactu', ['mode' => 'verifactu']);

        (new NowoVerifactuExtension())->prepend($container);

        /** @var array{orm: array{mappings: array{NowoVerifactuBundle: array{type: string, dir: string}}}} $config */
        $config = $container->getExtensionConfig('doctrine')[0];
        self::assertSame('xml', $config['orm']['mappings']['NowoVerifactuBundle']['type']);
        self::assertSame('Resources/config/doctrine', $config['orm']['mappings']['NowoVerifactuBundle']['dir']);

        $loaded = new ContainerBuilder();
        (new NowoVerifactuExtension())->load([['doctrine' => ['mapping' => 'xml']]], $loaded);
        self::assertSame('xml', $loaded->getParameter('nowo_verifactu.doctrine.mapping'));
    }

    public function testCompletenessAndRuntimeProviderOptions(): void
    {
        $defaults = new ContainerBuilder();
        (new NowoVerifactuExtension())->load([[]], $defaults);
        self::assertFalse($defaults->getParameter('nowo_verifactu.validation.strict'));
        self::assertNull($defaults->getParameter('nowo_verifactu.xml.default_regime_key'));
        self::assertSame(ConfigAeatEnvironmentProvider::class, (string) $defaults->getAlias(AeatEnvironmentProviderInterface::class));
        self::assertSame(ConfigCertificateProvider::class, (string) $defaults->getAlias(CertificateProviderInterface::class));
        self::assertSame(NullAeatSubmissionClient::class, (string) $defaults->getAlias('nowo_verifactu.aeat_submission_client'));

        $custom = new ContainerBuilder();
        (new NowoVerifactuExtension())->load([[
            'mode'       => 'no_verifactu',
            'validation' => ['strict' => true],
            'xml'        => ['default_regime_key' => '01'],
            'providers'  => ['aeat_environment' => 'app.environment', 'certificate' => 'app.certificates'],
            'hash_chain' => ['storage' => 'doctrine'],
        ]], $custom);
        self::assertTrue($custom->getParameter('nowo_verifactu.validation.strict'));
        self::assertSame('01', $custom->getParameter('nowo_verifactu.xml.default_regime_key'));
        self::assertSame('app.environment', (string) $custom->getAlias(AeatEnvironmentProviderInterface::class));
        self::assertSame('app.certificates', (string) $custom->getAlias(CertificateProviderInterface::class));
        self::assertSame(SoapAeatSubmissionClient::class, (string) $custom->getAlias(AeatSubmissionClientInterface::class), 'A certificate provider enables the SOAP client');
        self::assertSame(XadesBillingRecordSigner::class, (string) $custom->getAlias(BillingRecordSignerInterface::class));
    }

    public function testARuntimeEnvironmentProviderCountsAsProductionForTheChainStorageCheck(): void
    {
        $this->expectException(LogicException::class);

        (new NowoVerifactuExtension())->load([[
            'providers' => ['aeat_environment' => 'app.environment', 'certificate' => 'app.certificates'],
        ]], new ContainerBuilder());
    }
}
