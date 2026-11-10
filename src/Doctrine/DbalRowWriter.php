<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes rows of the bundle entities straight through the DBAL connection of their EntityManager.
 *
 * The bundle never calls EntityManager::flush(): that would also write the host's pending entities (architecture
 * rule 6). Table and column names come from the class metadata, so `doctrine.table_prefix` and a host naming strategy
 * are followed, and the statements run inside whatever transaction is open on the connection (the host's, or the one
 * opened by withChainLock()).
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
        $metadata   = $entityManager->getClassMetadata($entityClass);
        $connection = $entityManager->getConnection();

        if ($metadata->isIdGeneratorSequence()) {
            $sequenceName                                      = (string) ($metadata->sequenceGeneratorDefinition['sequenceName'] ?? '');
            $fields[$metadata->getSingleIdentifierFieldName()] = (int) $connection->fetchOne(
                $connection->getDatabasePlatform()->getSequenceNextValSQL($sequenceName),
            );
        }

        [$data, $types] = self::columns($entityManager, $entityClass, $fields);
        $connection->insert($metadata->getTableName(), $data, $types);
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
        [$data, $types]       = self::columns($entityManager, $entityClass, $fields);
        [$where, $whereTypes] = self::columns($entityManager, $entityClass, $criteria);

        return (int) $entityManager->getConnection()->update(
            $entityManager->getClassMetadata($entityClass)->getTableName(),
            $data,
            $where,
            $types + $whereTypes,
        );
    }

    /**
     * @param class-string $entityClass
     * @param array<string, mixed> $fields
     *
     * @return array{array<string, mixed>, array<string, string>}
     */
    private static function columns(EntityManagerInterface $entityManager, string $entityClass, array $fields): array
    {
        $metadata = $entityManager->getClassMetadata($entityClass);
        $data     = [];
        $types    = [];
        foreach ($fields as $field => $value) {
            $column         = $metadata->getColumnName($field);
            $data[$column]  = $value;
            $types[$column] = $metadata->getTypeOfField($field) ?? 'string';
        }

        return [$data, $types];
    }
}
