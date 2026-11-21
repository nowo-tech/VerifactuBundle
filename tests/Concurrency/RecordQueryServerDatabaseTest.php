<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Concurrency;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VerifactuBundle\Repository\BillingRecordQueryInterface;
use Nowo\VerifactuBundle\Repository\BillingRecordStoreInterface;
use Nowo\VerifactuBundle\Repository\DoctrineBillingRecordQuery;
use Nowo\VerifactuBundle\Tests\Support\RecordQueryScenarios;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The record query scenarios (1.10) on a real server database: MySQL / MariaDB (case- and accent-insensitive
 * collations: invoice numbers must still match exactly) and PostgreSQL (SUBSTRING / CONCAT issue-date filters and
 * orders). Skipped unless VERIFACTU_TEST_DATABASE_URL is set (the `concurrency` CI job sets it).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[Group('concurrency')]
final class RecordQueryServerDatabaseTest extends TestCase
{
    use RecordQueryScenarios;

    private ?ConcurrencyKernel $kernel = null;

    protected function setUp(): void
    {
        if (ConcurrencyKernel::databaseUrl() === '') {
            self::markTestSkipped('Set VERIFACTU_TEST_DATABASE_URL (MySQL, MariaDB or PostgreSQL) to run the server database tests.');
        }
        $this->kernel = new ConcurrencyKernel('test', false, 'doctrine');
        $this->kernel->boot();
        $schemaTool = new SchemaTool($this->entityManager());
        $metadata   = $this->entityManager()->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        if ($this->kernel instanceof ConcurrencyKernel) {
            $this->entityManager()->getConnection()->close();
            $this->kernel->shutdown();
        }
        $this->kernel = null;
    }

    protected function rqStore(): BillingRecordStoreInterface
    {
        $store = $this->kernel?->getContainer()->get('nowo_verifactu.billing_record_store');
        self::assertInstanceOf(BillingRecordStoreInterface::class, $store);

        return $store;
    }

    protected function rqQuery(): BillingRecordQueryInterface
    {
        $query = $this->kernel?->getContainer()->get('nowo_verifactu.billing_record_query');
        self::assertInstanceOf(DoctrineBillingRecordQuery::class, $query);

        return $query;
    }

    private function entityManager(): EntityManagerInterface
    {
        $doctrine = $this->kernel?->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $entityManager = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
