<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Nowo\VerifactuBundle\Entity\BillingRecordEntry;
use Nowo\VerifactuBundle\Entity\BillingRecordHashChain;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Service\ChainVerifier;
use Nowo\VerifactuBundle\Tests\Fixtures\HostXmlEntity\HostXmlInvoice;
use Nowo\VerifactuBundle\Tests\Kernel\XmlMappingDoctrineTestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DDD host: plain-PHP host entity mapped in XML and the bundle entities mapped from the bundle's XML files
 * (`nowo_verifactu.doctrine.mapping: xml`). The host references the records by issuer NIF + hash, never by relation.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class XmlMappingHostIntegrationTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return XmlMappingDoctrineTestKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $schemaTool = new SchemaTool($this->entityManager());
        $metadata   = $this->entityManager()->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testBundleEntitiesAreMappedFromXml(): void
    {
        $driver = $this->entityManager()->getConfiguration()->getMetadataDriverImpl();
        self::assertNotNull($driver);
        // DoctrineBundle 2.x/3.x wraps the chain in its own MappingDriver decorator
        if (!$driver instanceof MappingDriverChain && method_exists($driver, 'getDriver')) {
            $driver = $driver->getDriver();
        }
        self::assertInstanceOf(MappingDriverChain::class, $driver);
        self::assertInstanceOf(XmlDriver::class, $driver->getDrivers()['Nowo\VerifactuBundle\Entity'] ?? null);

        $chain = $this->entityManager()->getClassMetadata(BillingRecordHashChain::class);
        $log   = $this->entityManager()->getClassMetadata(BillingRecordEntry::class);
        self::assertSame('verifactu_hash_chain', $chain->getTableName());
        self::assertSame('verifactu_billing_record', $log->getTableName());
        self::assertSame(['issuerNif', 'position'], $log->getIdentifierFieldNames());
    }

    public function testProcessKeepsTheHostUnitOfWorkUntouchedAndTheChainVerifies(): void
    {
        $hostInvoice = new HostXmlInvoice('HOST-XML-1', '89890001K');
        $this->entityManager()->persist($hostInvoice);

        /** @var BillingRecordProcessor $processor */
        $processor = self::getContainer()->get(BillingRecordProcessor::class);
        $first     = $processor->process($this->record('FAC-XML-001'));
        $second    = $processor->process($this->record('FAC-XML-002'));

        self::assertSame([], $first['errors']);
        self::assertSame($first['record']->hash, $second['record']->previousHash);
        self::assertTrue($this->entityManager()->getUnitOfWork()->isScheduledForInsert($hostInvoice));
        self::assertSame(0, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM host_xml_invoice'));

        /** @var ChainVerifier $verifier */
        $verifier = self::getContainer()->get(ChainVerifier::class);
        $result   = $verifier->verify('89890001K');
        self::assertTrue($result->isValid());
        self::assertSame(2, $result->checkedRecords);
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
            operationDescription: 'DDD host invoice',
        );
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }
}
