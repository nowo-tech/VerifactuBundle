<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VerifactuBundle\Entity\BillingRecordEntry;
use Nowo\VerifactuBundle\Entity\BillingRecordHashChain;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\BillingRecordCriteria;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Repository\BillingRecordQueryInterface;
use Nowo\VerifactuBundle\Repository\BillingRecordStoreInterface;
use Nowo\VerifactuBundle\Repository\DoctrineBillingRecordQuery;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Service\ChainVerifier;
use Nowo\VerifactuBundle\Tests\Fixtures\HostXmlEntity\HostXmlInvoice;
use Nowo\VerifactuBundle\Tests\Kernel\HostMappedDoctrineTestKernel;
use Nowo\VerifactuBundle\Tests\Support\RecordQueryScenarios;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DDD host mapping the bundle entities itself on a second entity manager (`doctrine.register_mapping: false`, 1.10):
 * the chain head, the record store and the record query all follow that manager; the host's default manager and
 * its unit of work are never touched.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class HostMappedEntityManagerIntegrationTest extends KernelTestCase
{
    use RecordQueryScenarios;

    protected static function getKernelClass(): string
    {
        return HostMappedDoctrineTestKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        foreach (['default', 'verifactu'] as $name) {
            $entityManager = $this->manager($name);
            $schemaTool    = new SchemaTool($entityManager);
            $metadata      = $entityManager->getMetadataFactory()->getAllMetadata();
            $schemaTool->dropSchema($metadata);
            $schemaTool->createSchema($metadata);
        }
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

    public function testTheBundleEntitiesLiveOnlyOnTheHostsSecondManager(): void
    {
        /** @var ManagerRegistry $registry */
        $registry = self::getContainer()->get('doctrine');

        self::assertSame($this->manager('verifactu'), $registry->getManagerForClass(BillingRecordEntry::class));
        self::assertSame($this->manager('verifactu'), $registry->getManagerForClass(BillingRecordHashChain::class));
        self::assertTrue($this->manager('default')->getMetadataFactory()->isTransient(BillingRecordEntry::class));
        self::assertSame([HostXmlInvoice::class], array_map(static fn ($metadata): string => $metadata->getName(), $this->manager('default')->getMetadataFactory()->getAllMetadata()));
        self::assertFalse(self::getContainer()->getParameter('nowo_verifactu.doctrine.register_mapping'));
    }

    public function testProcessWritesOnTheBundleManagerAndTheQueryReadsIt(): void
    {
        $hostInvoice = new HostXmlInvoice('HOST-EM-1', '89890001K');
        $this->manager('default')->persist($hostInvoice);

        /** @var BillingRecordProcessor $processor */
        $processor = self::getContainer()->get(BillingRecordProcessor::class);
        $first     = $processor->process($this->record('FAC-EM-001'));
        $second    = $processor->process($this->record('FAC-EM-002'));
        self::assertSame([], $first['errors']);
        self::assertSame($first['record']->hash, $second['record']->previousHash);

        $records = $this->manager('verifactu')->getConnection()->fetchOne('SELECT COUNT(*) FROM verifactu_billing_record');
        self::assertSame(2, (int) $records);
        self::assertFalse($this->manager('default')->getConnection()->createSchemaManager()->tablesExist(['verifactu_billing_record']));
        self::assertTrue($this->manager('default')->getUnitOfWork()->isScheduledForInsert($hostInvoice));

        $query = $this->rqQuery();
        self::assertSame($second['record']->hash, $query->findByInvoice('89890001K', 'FAC-EM-002')?->hash);
        self::assertSame(2, $query->count(new BillingRecordCriteria('89890001K')));

        /** @var ChainVerifier $verifier */
        $verifier = self::getContainer()->get(ChainVerifier::class);
        self::assertTrue($verifier->verify('89890001K')->isValid());
    }

    private function record(string $invoiceSeriesNumber): BillingRecord
    {
        return new BillingRecord(
            RecordType::Alta,
            '89890001K',
            $invoiceSeriesNumber,
            '09-07-2026',
            'F1',
            '21.00',
            '121.00',
            '2026-07-09T16:00:00+02:00',
            issuerName: 'Doctrine Test Issuer',
            operationDescription: 'Host-mapped invoice',
        );
    }

    private function manager(string $name): EntityManagerInterface
    {
        /** @var ManagerRegistry $registry */
        $registry      = self::getContainer()->get('doctrine');
        $entityManager = $registry->getManager($name);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
