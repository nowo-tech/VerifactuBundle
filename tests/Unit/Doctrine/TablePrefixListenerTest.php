<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\VerifactuBundle\Doctrine\TablePrefixListener;
use Nowo\VerifactuBundle\Entity\BillingRecordHashChain;
use PHPUnit\Framework\TestCase;
use stdClass;

use function class_exists;
use function implode;
use function sys_get_temp_dir;

use const CASE_LOWER;
use const PHP_VERSION_ID;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class TablePrefixListenerTest extends TestCase
{
    public function testEmptyPrefixKeepsHistoricalNames(): void
    {
        $metadata = $this->entityManager('')->getClassMetadata(BillingRecordHashChain::class);

        self::assertSame('verifactu_hash_chain', $metadata->getTableName());
        self::assertArrayHasKey('uniq_verifactu_hash_chain_issuer', $metadata->table['uniqueConstraints'] ?? []);
    }

    public function testPrefixIsPrependedToTheTableAndUniqueConstraint(): void
    {
        $metadata = $this->entityManager('app_')->getClassMetadata(BillingRecordHashChain::class);

        self::assertSame('app_verifactu_hash_chain', $metadata->getTableName());
        self::assertArrayHasKey('app_uniq_verifactu_hash_chain_issuer', $metadata->table['uniqueConstraints'] ?? []);
        self::assertArrayNotHasKey('uniq_verifactu_hash_chain_issuer', $metadata->table['uniqueConstraints'] ?? []);
    }

    public function testSchemaToolCreatesPrefixedTablesOnly(): void
    {
        $em  = $this->entityManager('app_');
        $sql = implode("\n", (new SchemaTool($em))->getCreateSchemaSql($em->getMetadataFactory()->getAllMetadata()));

        self::assertStringContainsString('CREATE TABLE app_verifactu_hash_chain', $sql);
        self::assertStringContainsString('app_uniq_verifactu_hash_chain_issuer', $sql);
        self::assertStringNotContainsString('CREATE TABLE verifactu_', $sql);

        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $em->persist(new BillingRecordHashChain('89890001K', 'FAC-001', '09-07-2026', 'ABC'));
        $em->flush();
        self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM app_verifactu_hash_chain'));
    }

    public function testManyToManyJoinTablesOfBundleEntitiesArePrefixed(): void
    {
        if (!class_exists(ManyToManyOwningSideMapping::class)) {
            self::markTestSkipped('Join table mapping objects exist since doctrine/orm 3.');
        }

        // The bundle entity has no many-to-many association: map a synthetic one on fresh metadata of it.
        $metadata = new ClassMetadata(BillingRecordHashChain::class);
        $metadata->setPrimaryTable(['name' => 'verifactu_synthetic']);
        $metadata->mapManyToMany([
            'fieldName'    => 'tags',
            'targetEntity' => BillingRecordHashChain::class,
            'joinTable'    => [
                'name'               => 'verifactu_synthetic_tag',
                'joinColumns'        => [['name' => 'synthetic_id', 'referencedColumnName' => 'id']],
                'inverseJoinColumns' => [['name' => 'tag_id', 'referencedColumnName' => 'id']],
            ],
        ]);

        (new TablePrefixListener('app_'))->apply($metadata);

        self::assertSame('app_verifactu_synthetic', $metadata->getTableName());
        self::assertSame('app_verifactu_synthetic_tag', $metadata->getAssociationMapping('tags')['joinTable']['name']);
    }

    public function testNonBundleEntitiesAreIgnored(): void
    {
        $metadata = new ClassMetadata(stdClass::class);
        $metadata->setPrimaryTable(['name' => 'host_table', 'uniqueConstraints' => ['uniq_host' => ['columns' => ['a']]]]);

        $listener = new TablePrefixListener('app_');
        $listener->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $this->entityManager('')));

        self::assertSame('host_table', $metadata->getTableName());
        self::assertArrayHasKey('uniq_host', $metadata->table['uniqueConstraints'] ?? []);
    }

    private function entityManager(string $prefix): EntityManagerInterface
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__ . '/../../../src/Entity']));
        $config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        $config->setProxyDir(sys_get_temp_dir() . '/nowo-verifactu-test-proxies');
        $config->setProxyNamespace('NowoVerifactuTestProxies');
        $config->enableNativeLazyObjects(PHP_VERSION_ID >= 80400);

        $eventManager = new EventManager();
        $eventManager->addEventListener(Events::loadClassMetadata, new TablePrefixListener($prefix));

        return new EntityManager(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
            $config,
            $eventManager,
        );
    }
}
