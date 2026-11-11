<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Integration;

use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\VerifactuBundle\Entity\BillingRecordEntry;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\IssuerIdentity;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Model\SoftwareIdentity;
use Nowo\VerifactuBundle\Model\StoredBillingRecord;
use Nowo\VerifactuBundle\Repository\BillingRecordStoreInterface;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Service\ChainVerifier;
use Nowo\VerifactuBundle\Tests\Kernel\XmlMappingDoctrineTestKernel;
use Nowo\VerifactuBundle\Tests\Support\DatabaseBackedIdentityProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function sprintf;

/**
 * FrankenPHP worker mode with FRANKENPHP_RESET_KERNEL=false: one booted kernel (no debug, so no profiler or traceable
 * dispatcher keeps data), no services_resetter, no EntityManager::clear() by the host, many "requests" in a row.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class WorkerModeWithoutKernelResetTest extends KernelTestCase
{
    private const REQUESTS = 1000;

    protected static function getKernelClass(): string
    {
        return XmlMappingDoctrineTestKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel(['debug' => false]);

        $schemaTool = new SchemaTool($this->entityManager());
        $metadata   = $this->entityManager()->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testProviderValuesEditedBetweenTwoRequestsAreUsedImmediately(): void
    {
        $provider  = $this->provider();
        $processor = $this->processor();

        $first = $processor->process($this->record('', 'FAC-W-1'));

        // The host edits Development → Invoicing between two requests of the same worker
        $provider->issuer   = new IssuerIdentity('B12345674', 'Renamed Issuer SL');
        $provider->software = new SoftwareIdentity('Other Producer SL', 'B12345674', 'Beacon', 'SB', '2.0.0', '1');

        $second = $processor->process($this->record('', 'FAC-W-1'));

        self::assertSame([], $second['errors']);
        self::assertSame('89890001K', $first['record']->issuerNif);
        self::assertSame('B12345674', $second['record']->issuerNif);
        self::assertSame('', (string) $second['record']->previousHash, 'A new issuer starts its own chain');
        self::assertNotSame($first['record']->hash, $second['record']->hash);
        self::assertStringContainsString('<NombreRazonEmisor>Renamed Issuer SL</NombreRazonEmisor>', (string) $second['record']->xml);
        self::assertStringContainsString('<NombreRazon>Other Producer SL</NombreRazon>', (string) $second['record']->xml);
        self::assertStringContainsString('<IdSistemaInformatico>SB</IdSistemaInformatico>', (string) $second['record']->xml);
        self::assertStringContainsString('<Version>2.0.0</Version>', (string) $second['record']->xml);
    }

    public function testManyRequestsForTwoIssuersKeepMemoryFlatAndTheChainsApart(): void
    {
        $provider      = $this->provider();
        $processor     = $this->processor();
        $entityManager = $this->entityManager();
        $connection    = $entityManager->getConnection();
        $baseline      = 0;

        for ($i = 1; $i <= self::REQUESTS; ++$i) {
            // Each request belongs to one tenant: the host issuer provider returns that tenant's issuer.
            $issuer           = $i % 2 === 0 ? 'B12345674' : '89890001K';
            $provider->issuer = new IssuerIdentity($issuer, 'Tenant ' . $issuer);
            $result           = $processor->process($this->record($issuer, sprintf('FAC-%05d', $i)));

            self::assertSame([], $result['errors']);
            self::assertSame(0, $entityManager->getUnitOfWork()->size(), 'No managed entity may survive a request');
            self::assertFalse($connection->isTransactionActive(), 'The chain lock must be released at the end of the call');

            if ($i === 200) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }

        gc_collect_cycles();
        self::assertLessThan(1024 * 1024, memory_get_usage() - $baseline, 'Memory must stay flat across requests');

        /** @var BillingRecordStoreInterface $store */
        $store   = self::getContainer()->get('nowo_verifactu.billing_record_store');
        $hashesA = array_map(static fn (StoredBillingRecord $s): string => (string) $s->record->hash, [...$store->findByIssuer('89890001K')]);
        $hashesB = array_map(static fn (StoredBillingRecord $s): string => (string) $s->record->hash, [...$store->findByIssuer('B12345674')]);
        self::assertCount(self::REQUESTS / 2, $hashesA);
        self::assertCount(self::REQUESTS / 2, $hashesB);
        self::assertSame([], array_intersect($hashesA, $hashesB));

        /** @var ChainVerifier $verifier */
        $verifier = self::getContainer()->get(ChainVerifier::class);
        self::assertTrue($verifier->verify('89890001K')->isValid());
        self::assertTrue($verifier->verify('B12345674')->isValid());
    }

    public function testAFailedRequestLeavesNoOpenTransactionForTheNextOne(): void
    {
        $processor  = $this->processor();
        $connection = $this->entityManager()->getConnection();
        $processor->process($this->record('89890001K', 'FAC-OK-1'));

        $table = $this->entityManager()->getClassMetadata(BillingRecordEntry::class)->getTableName();
        $connection->executeStatement('ALTER TABLE ' . $table . ' RENAME TO broken_log');
        try {
            $processor->process($this->record('89890001K', 'FAC-FAIL'));
            self::fail('The failing append must be rethrown');
        } catch (DbalException) {
        }
        self::assertFalse($connection->isTransactionActive());
        $connection->executeStatement('ALTER TABLE broken_log RENAME TO ' . $table);

        $next = $processor->process($this->record('89890001K', 'FAC-OK-2'));
        self::assertSame([], $next['errors']);
        self::assertTrue($this->entityManager()->isOpen());
        /** @var ChainVerifier $verifier */
        $verifier = self::getContainer()->get(ChainVerifier::class);
        self::assertTrue($verifier->verify('89890001K')->isValid(), 'The failed record rolled back with its chain head');
    }

    private function record(string $issuerNif, string $number): BillingRecord
    {
        return new BillingRecord(RecordType::Alta, $issuerNif, $number, '09-07-2026', 'F1', '21.00', '121.00', '2026-07-09T16:00:00+02:00', operationDescription: 'Worker invoice');
    }

    private function provider(): DatabaseBackedIdentityProvider
    {
        /** @var DatabaseBackedIdentityProvider $provider */
        $provider = self::getContainer()->get(DatabaseBackedIdentityProvider::class);

        return $provider;
    }

    private function processor(): BillingRecordProcessor
    {
        /** @var BillingRecordProcessor $processor */
        $processor = self::getContainer()->get(BillingRecordProcessor::class);

        return $processor;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }
}
