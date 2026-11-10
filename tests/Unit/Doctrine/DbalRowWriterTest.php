<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\DefaultQuoteStrategy;
use Nowo\VerifactuBundle\Doctrine\DbalRowWriter;
use Nowo\VerifactuBundle\Entity\BillingRecordHashChain;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The server-database branches (sequences, ON CONFLICT, ON DUPLICATE KEY, other platforms) cannot run on the SQLite
 * kernel of the unit / integration suites; tests/Concurrency runs them for real on MySQL, MariaDB and PostgreSQL.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class DbalRowWriterTest extends TestCase
{
    public function testInsertFetchesTheNextSequenceValueFirstAndQuotesThroughTheStrategy(): void
    {
        $platform = $this->createMock(AbstractPlatform::class);
        $platform->method('getSequenceNextValSQL')->with('verifactu_hash_chain_id_seq')->willReturn('SELECT NEXTVAL');
        $platform->method('quoteIdentifier')->willReturnCallback(static fn (string $name): string => '"' . $name . '"');
        $platform->method('quoteSingleIdentifier')->willReturnCallback(static fn (string $name): string => '"' . $name . '"');

        $connection = $this->connection($platform);
        $connection->method('fetchOne')->with('SELECT NEXTVAL')->willReturn('42');
        $connection->expects(self::once())->method('insert')->with(
            'billing.verifactu_hash_chain',
            ['"hash"' => 'ABC', 'id' => 42],
            ['"hash"' => 'string', 'id' => 'integer'],
        );

        DbalRowWriter::insert($this->entityManager($this->metadata(true, 'billing'), $connection), BillingRecordHashChain::class, ['hash' => 'ABC']);
    }

    public function testInsertIfAbsentUsesOnConflictOnPostgreSql(): void
    {
        $connection = $this->connection($this->createMock(PostgreSQLPlatform::class));
        $connection->expects(self::once())->method('executeStatement')->with(
            'INSERT INTO verifactu_hash_chain (issuer_nif, hash) VALUES (?, ?) ON CONFLICT (issuer_nif) DO NOTHING',
            ['89890001K', ''],
            ['string', 'string'],
        );

        DbalRowWriter::insertIfAbsent($this->entityManager($this->metadata(), $connection), BillingRecordHashChain::class, ['issuerNif' => '89890001K', 'hash' => ''], 'issuerNif');
    }

    public function testInsertIfAbsentUsesOnDuplicateKeyOnMySql(): void
    {
        $connection = $this->connection($this->createMock(MySQLPlatform::class));
        $connection->expects(self::once())->method('executeStatement')->with(
            'INSERT INTO verifactu_hash_chain (issuer_nif, hash) VALUES (?, ?) ON DUPLICATE KEY UPDATE issuer_nif = issuer_nif',
            ['89890001K', ''],
            ['string', 'string'],
        );

        DbalRowWriter::insertIfAbsent($this->entityManager($this->metadata(), $connection), BillingRecordHashChain::class, ['issuerNif' => '89890001K', 'hash' => ''], 'issuerNif');
    }

    public function testInsertIfAbsentChecksFirstOnOtherPlatforms(): void
    {
        $missing = $this->connection($this->createMock(AbstractPlatform::class));
        $missing->method('fetchOne')->with('SELECT 1 FROM verifactu_hash_chain WHERE issuer_nif = ?', ['89890001K'])->willReturn(false);
        $missing->expects(self::once())->method('insert')->with('verifactu_hash_chain', ['issuer_nif' => '89890001K', 'hash' => ''], ['issuer_nif' => 'string', 'hash' => 'string']);
        DbalRowWriter::insertIfAbsent($this->entityManager($this->metadata(), $missing), BillingRecordHashChain::class, ['issuerNif' => '89890001K', 'hash' => ''], 'issuerNif');

        $present = $this->connection($this->createMock(AbstractPlatform::class));
        $present->method('fetchOne')->willReturn(1);
        $present->expects(self::never())->method('insert');
        DbalRowWriter::insertIfAbsent($this->entityManager($this->metadata(), $present), BillingRecordHashChain::class, ['issuerNif' => '89890001K', 'hash' => ''], 'issuerNif');
    }

    /**
     * @return ClassMetadata<BillingRecordHashChain>
     */
    private function metadata(bool $sequence = false, ?string $schema = null): ClassMetadata
    {
        $metadata = new ClassMetadata(BillingRecordHashChain::class);
        $metadata->setPrimaryTable($schema === null ? ['name' => 'verifactu_hash_chain'] : ['name' => 'verifactu_hash_chain', 'schema' => $schema]);
        $metadata->mapField(['fieldName' => 'id', 'columnName' => 'id', 'type' => 'integer', 'id' => true]);
        $metadata->mapField(['fieldName' => 'issuerNif', 'columnName' => 'issuer_nif', 'type' => 'string']);
        $metadata->mapField(['fieldName' => 'hash', 'columnName' => $sequence ? '`hash`' : 'hash', 'type' => 'string']);
        if ($sequence) {
            $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_SEQUENCE);
            $metadata->setSequenceGeneratorDefinition(['sequenceName' => 'verifactu_hash_chain_id_seq', 'allocationSize' => '1', 'initialValue' => '1']);
        }

        return $metadata;
    }

    private function connection(AbstractPlatform $platform): Connection&MockObject
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);

        return $connection;
    }

    /**
     * @param ClassMetadata<BillingRecordHashChain> $metadata
     */
    private function entityManager(ClassMetadata $metadata, Connection $connection): EntityManagerInterface
    {
        $configuration = $this->createMock(Configuration::class);
        $configuration->method('getQuoteStrategy')->willReturn(new DefaultQuoteStrategy());

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willReturn($metadata);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getConfiguration')->willReturn($configuration);

        return $entityManager;
    }
}
