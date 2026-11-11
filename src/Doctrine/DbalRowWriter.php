<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Doctrine;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

use function count;

/**
 * Writes rows of the bundle entities straight through the DBAL connection of their EntityManager.
 *
 * The bundle never calls EntityManager::flush(): that would also write the host's pending entities (architecture
 * rule 6). Table (with its schema) and column names come from the class metadata through the ORM quote strategy, so
 * `doctrine.table_prefix`, a host naming strategy and quoted identifiers are followed, and the statements run inside
 * whatever transaction is open on the connection (the host's, or the one opened by withChainLock()).
 *
 * @internal
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class DbalRowWriter
{
    /**
     * Inserts one row; a sequence-generated identifier is fetched first (identity columns fill themselves).
     *
     * @param class-string $entityClass
     * @param array<string, mixed> $fields field name => value
     */
    public static function insert(EntityManagerInterface $entityManager, string $entityClass, array $fields): void
    {
        $metadata       = $entityManager->getClassMetadata($entityClass);
        [$data, $types] = self::columns($entityManager, $metadata, self::withSequenceIdentifier($entityManager, $metadata, $fields));

        $entityManager->getConnection()->insert(self::tableName($entityManager, $metadata), $data, $types);
    }

    /**
     * Inserts the row unless a row with the same unique value already exists, without failing and without aborting
     * the surrounding transaction: `ON CONFLICT DO NOTHING` (PostgreSQL, SQLite) or `ON DUPLICATE KEY UPDATE` as a
     * no-op (MySQL, MariaDB). On other platforms it inserts when no row is found and lets a concurrent duplicate fail.
     *
     * @param class-string $entityClass
     * @param array<string, mixed> $fields field name => value
     * @param string $uniqueField the field of the unique constraint the conflict is detected on
     */
    public static function insertIfAbsent(EntityManagerInterface $entityManager, string $entityClass, array $fields, string $uniqueField): void
    {
        $metadata       = $entityManager->getClassMetadata($entityClass);
        $connection     = $entityManager->getConnection();
        $platform       = $connection->getDatabasePlatform();
        $fields         = self::withSequenceIdentifier($entityManager, $metadata, $fields);
        [$data, $types] = self::columns($entityManager, $metadata, $fields);
        $table          = self::tableName($entityManager, $metadata);
        $unique         = self::columnName($entityManager, $metadata, $uniqueField);

        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', array_keys($data)) . ') VALUES ('
            . implode(', ', array_fill(0, count($data), '?')) . ')';
        if ($platform instanceof PostgreSQLPlatform || $platform instanceof SQLitePlatform) {
            $connection->executeStatement($sql . ' ON CONFLICT (' . $unique . ') DO NOTHING', array_values($data), array_values($types));

            return;
        }
        if ($platform instanceof AbstractMySQLPlatform) {
            $connection->executeStatement($sql . ' ON DUPLICATE KEY UPDATE ' . $unique . ' = ' . $unique, array_values($data), array_values($types));

            return;
        }

        if ($connection->fetchOne('SELECT 1 FROM ' . $table . ' WHERE ' . $unique . ' = ?', [$fields[$uniqueField]]) === false) {
            $connection->insert($table, $data, $types);
        }
    }

    /**
     * The quoted table name of an entity (table prefix and quote strategy applied), for hand-written statements.
     *
     * @param class-string $entityClass
     */
    public static function table(EntityManagerInterface $entityManager, string $entityClass): string
    {
        return self::tableName($entityManager, $entityManager->getClassMetadata($entityClass));
    }

    /**
     * The quoted column name of an entity field, for hand-written statements.
     *
     * @param class-string $entityClass
     */
    public static function column(EntityManagerInterface $entityManager, string $entityClass, string $field): string
    {
        return self::columnName($entityManager, $entityManager->getClassMetadata($entityClass), $field);
    }

    /**
     * Updates the rows matching the criteria.
     *
     * @param class-string $entityClass
     * @param array<string, mixed> $fields field name => new value
     * @param array<string, mixed> $criteria field name => value
     */
    public static function update(EntityManagerInterface $entityManager, string $entityClass, array $fields, array $criteria): int
    {
        $metadata             = $entityManager->getClassMetadata($entityClass);
        [$data, $types]       = self::columns($entityManager, $metadata, $fields);
        [$where, $whereTypes] = self::columns($entityManager, $metadata, $criteria);

        return (int) $entityManager->getConnection()->update(self::tableName($entityManager, $metadata), $data, $where, $types + $whereTypes);
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private static function withSequenceIdentifier(EntityManagerInterface $entityManager, ClassMetadata $metadata, array $fields): array
    {
        if ($metadata->isIdGeneratorSequence()) {
            $connection                                        = $entityManager->getConnection();
            $sequenceName                                      = (string) ($metadata->sequenceGeneratorDefinition['sequenceName'] ?? '');
            $fields[$metadata->getSingleIdentifierFieldName()] = (int) $connection->fetchOne(
                $connection->getDatabasePlatform()->getSequenceNextValSQL($sequenceName),
            );
        }

        return $fields;
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed> $fields
     *
     * @return array{array<string, mixed>, array<string, string>}
     */
    private static function columns(EntityManagerInterface $entityManager, ClassMetadata $metadata, array $fields): array
    {
        $data  = [];
        $types = [];
        foreach ($fields as $field => $value) {
            $column         = self::columnName($entityManager, $metadata, $field);
            $data[$column]  = $value;
            $types[$column] = $metadata->getTypeOfField($field) ?? 'string';
        }

        return [$data, $types];
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private static function tableName(EntityManagerInterface $entityManager, ClassMetadata $metadata): string
    {
        return $entityManager->getConfiguration()->getQuoteStrategy()->getTableName(
            $metadata,
            $entityManager->getConnection()->getDatabasePlatform(),
        );
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private static function columnName(EntityManagerInterface $entityManager, ClassMetadata $metadata, string $field): string
    {
        return $entityManager->getConfiguration()->getQuoteStrategy()->getColumnName(
            $field,
            $metadata,
            $entityManager->getConnection()->getDatabasePlatform(),
        );
    }
}
