<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\DependencyInjection;

use Nowo\VerifactuBundle\Client\AeatSubmissionClientInterface;
use Nowo\VerifactuBundle\Client\NullAeatSubmissionClient;
use Nowo\VerifactuBundle\Client\SoapAeatSubmissionClient;
use Nowo\VerifactuBundle\DependencyInjection\NowoVerifactuExtension;
use Nowo\VerifactuBundle\Repository\DoctrineHashChainRepository;
use Nowo\VerifactuBundle\Repository\HashChainRepositoryInterface;
use Nowo\VerifactuBundle\Repository\InMemoryHashChainRepository;
use Nowo\VerifactuBundle\Signer\BillingRecordSignerInterface;
use Nowo\VerifactuBundle\Signer\XadesBillingRecordSigner;
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
}
