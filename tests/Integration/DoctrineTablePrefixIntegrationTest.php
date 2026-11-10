<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VerifactuBundle\Entity\BillingRecordEntry;
use Nowo\VerifactuBundle\Entity\BillingRecordHashChain;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\HashChainState;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Repository\BillingRecordStoreInterface;
use Nowo\VerifactuBundle\Repository\DoctrineHashChainRepository;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Tests\Kernel\PrefixedDoctrineTestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function str_repeat;

/**
 * `nowo_verifactu.doctrine.table_prefix` set in a real kernel: the bundle table is renamed and the Doctrine repository
 * (DQL + row lock) keeps working on it (REQ-ORM-002).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class DoctrineTablePrefixIntegrationTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return PrefixedDoctrineTestKernel::class;
    }

    public function testPrefixRenamesTheBundleTableAndTheRepositoryUsesIt(): void
    {
        self::bootKernel();
        $entityManager = $this->entityManager();
        $metadata      = $entityManager->getClassMetadata(BillingRecordHashChain::class);

        self::assertSame('app_verifactu_hash_chain', $metadata->getTableName());
        self::assertArrayHasKey('app_uniq_verifactu_hash_chain_issuer', $metadata->table['uniqueConstraints'] ?? []);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema([$metadata]);
        $schemaTool->createSchema([$metadata]);

        /** @var DoctrineHashChainRepository $repository */
        $repository = self::getContainer()->get(DoctrineHashChainRepository::class);
        $repository->withChainLock('89890001K', static function () use ($repository): void {
            $repository->storeLastState(new HashChainState('89890001K', 'FAC-001', '09-07-2026', str_repeat('A', 64)));
        });

        $connection = $entityManager->getConnection();
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM app_verifactu_hash_chain'));
        self::assertFalse($connection->createSchemaManager()->tablesExist(['verifactu_hash_chain']));
        self::assertSame('FAC-001', $repository->getLastState('89890001K')?->invoiceSeriesNumber);
    }

    public function testPrefixRenamesTheRecordStoreTableAndTheStoreUsesIt(): void
    {
        self::bootKernel();
        $entityManager = $this->entityManager();
        $metadata      = $entityManager->getMetadataFactory()->getAllMetadata();
        $log           = $entityManager->getClassMetadata(BillingRecordEntry::class);

        self::assertSame('app_verifactu_billing_record', $log->getTableName());
        self::assertArrayHasKey('app_uniq_verifactu_billing_record_hash', $log->table['uniqueConstraints'] ?? []);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        /** @var BillingRecordProcessor $processor */
        $processor = self::getContainer()->get(BillingRecordProcessor::class);
        $result    = $processor->process(new BillingRecord(RecordType::Alta, '89890001K', 'FAC-001', '09-07-2026', 'F1', '21.00', '121.00', ''));
        self::assertSame([], $result['errors']);

        /** @var BillingRecordStoreInterface $store */
        $store = self::getContainer()->get('nowo_verifactu.billing_record_store');
        $store->markSubmitted('89890001K', (string) $result['record']->hash, true, 'CSV');

        $connection = $entityManager->getConnection();
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM app_verifactu_billing_record WHERE submission_status = ?', ['accepted']));
        self::assertFalse($connection->createSchemaManager()->tablesExist(['verifactu_billing_record']));
        self::assertCount(1, [...$store->findByIssuer('89890001K')]);
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var ManagerRegistry $registry */
        $registry      = self::getContainer()->get('doctrine');
        $entityManager = $registry->getManagerForClass(BillingRecordHashChain::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
