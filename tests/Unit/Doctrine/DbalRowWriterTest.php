<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\VerifactuBundle\Doctrine\DbalRowWriter;
use Nowo\VerifactuBundle\Entity\BillingRecordHashChain;
use PHPUnit\Framework\TestCase;

/**
 * The sequence branch (PostgreSQL with ORM 2 `AUTO`) cannot run on the SQLite integration kernel.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class DbalRowWriterTest extends TestCase
{
    public function testInsertFetchesTheNextSequenceValueFirst(): void
    {
        $metadata = new ClassMetadata(BillingRecordHashChain::class);
        $metadata->setPrimaryTable(['name' => 'verifactu_hash_chain']);
        $metadata->mapField(['fieldName' => 'id', 'columnName' => 'id', 'type' => 'integer', 'id' => true]);
        $metadata->mapField(['fieldName' => 'hash', 'columnName' => 'hash', 'type' => 'string']);
        $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_SEQUENCE);
        $metadata->setSequenceGeneratorDefinition(['sequenceName' => 'verifactu_hash_chain_id_seq', 'allocationSize' => '1', 'initialValue' => '1']);

        $platform = $this->createMock(AbstractPlatform::class);
        $platform->method('getSequenceNextValSQL')->with('verifactu_hash_chain_id_seq')->willReturn('SELECT NEXTVAL');

        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('fetchOne')->with('SELECT NEXTVAL')->willReturn('42');
        $connection->expects(self::once())->method('insert')->with(
            'verifactu_hash_chain',
            ['hash' => 'ABC', 'id' => 42],
            ['hash' => 'string', 'id' => 'integer'],
        );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willReturn($metadata);
        $entityManager->method('getConnection')->willReturn($connection);

        DbalRowWriter::insert($entityManager, BillingRecordHashChain::class, ['hash' => 'ABC']);
    }
}
