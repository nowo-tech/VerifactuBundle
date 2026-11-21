<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\VerifactuBundle\Repository\BillingRecordQueryInterface;
use Nowo\VerifactuBundle\Repository\BillingRecordStoreInterface;
use Nowo\VerifactuBundle\Repository\DoctrineBillingRecordQuery;
use Nowo\VerifactuBundle\Tests\Kernel\XmlMappingDoctrineTestKernel;
use Nowo\VerifactuBundle\Tests\Support\RecordQueryScenarios;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DDD host (bundle entities from the shipped XML, `doctrine.mapping: xml`): the Doctrine record query (1.10).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class XmlMappingRecordQueryIntegrationTest extends KernelTestCase
{
    use RecordQueryScenarios;

    protected static function getKernelClass(): string
    {
        return XmlMappingDoctrineTestKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $schemaTool    = new SchemaTool($entityManager);
        $metadata      = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected function rqStore(): BillingRecordStoreInterface
    {
        /** @var BillingRecordStoreInterface $store */
        $store = self::getContainer()->get('nowo_verifactu.billing_record_store');

        return $store;
    }

    protected function rqQuery(): BillingRecordQueryInterface
    {
        $query = self::getContainer()->get('nowo_verifactu.billing_record_query');
        self::assertInstanceOf(DoctrineBillingRecordQuery::class, $query);

        return $query;
    }
}
