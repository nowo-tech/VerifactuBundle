<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Concurrency;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VerifactuBundle\Entity\BillingRecordHashChain;
use Nowo\VerifactuBundle\Repository\DoctrineBillingRecordStore;
use Nowo\VerifactuBundle\Repository\HashChainRepositoryInterface;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Tests\Support\SubmissionQueueDatabaseScenarios;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The audit LJ regressions of the Doctrine submission queue (SubmissionQueueDatabaseScenarios) on a real server
 * database: MySQL, MariaDB and PostgreSQL. Skipped unless VERIFACTU_TEST_DATABASE_URL is set (the `concurrency` CI job
 * sets it); the same scenarios run on SQLite in DoctrineSubmissionQueueIntegrationTest.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[Group('concurrency')]
final class SubmissionQueueServerDatabaseTest extends TestCase
{
    use SubmissionQueueDatabaseScenarios;

    private ?ConcurrencyKernel $kernel = null;

    protected function setUp(): void
    {
        if (ConcurrencyKernel::databaseUrl() === '') {
            self::markTestSkipped('Set VERIFACTU_TEST_DATABASE_URL (MySQL, MariaDB or PostgreSQL) to run the server database tests.');
        }
        $this->kernel = new ConcurrencyKernel('test', false, 'doctrine');
        $this->kernel->boot();
        $schemaTool = new SchemaTool($this->ljEntityManager());
        $metadata   = $this->ljEntityManager()->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        if ($this->kernel instanceof ConcurrencyKernel) {
            $this->ljEntityManager()->getConnection()->close();
            $this->kernel->shutdown();
        }
        $this->kernel = null;
    }

    protected function ljStore(): DoctrineBillingRecordStore
    {
        $store = $this->container()->get('nowo_verifactu.billing_record_store');
        self::assertInstanceOf(DoctrineBillingRecordStore::class, $store);

        return $store;
    }

    protected function ljProcessor(): BillingRecordProcessor
    {
        $processor = $this->container()->get(BillingRecordProcessor::SERVICE_NAME);
        self::assertInstanceOf(BillingRecordProcessor::class, $processor);

        return $processor;
    }

    protected function ljHeads(): HashChainRepositoryInterface
    {
        $heads = $this->doctrine()->getRepository(BillingRecordHashChain::class);
        if (!$heads instanceof HashChainRepositoryInterface) {
            throw new RuntimeException('The chain head repository is not the Doctrine one.');
        }

        return $heads;
    }

    protected function ljEntityManager(): EntityManagerInterface
    {
        $entityManager = $this->doctrine()->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private function doctrine(): ManagerRegistry
    {
        $doctrine = $this->container()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);

        return $doctrine;
    }

    private function container(): \Psr\Container\ContainerInterface
    {
        self::assertInstanceOf(ConcurrencyKernel::class, $this->kernel);

        return $this->kernel->getContainer();
    }
}
