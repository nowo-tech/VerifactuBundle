<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Doctrine;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Nowo\VerifactuBundle\DependencyInjection\NowoVerifactuExtension;

use function is_array;
use function is_string;
use function str_starts_with;

/**
 * Prepends `doctrine.table_prefix` to the tables, indexes, unique constraints and many-to-many join tables of the
 * bundle entities (REQ-ORM-002). Entities outside {@see self::ENTITY_NAMESPACE} are never touched; an empty prefix is
 * a no-op, so the default configuration keeps the historical `verifactu_hash_chain` name.
 *
 * Registered by {@see NowoVerifactuExtension} as a Doctrine `loadClassMetadata` listener. The bundle never hard-codes
 * table names in SQL: queries are DQL on the entity class, so they follow the prefixed metadata.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final readonly class TablePrefixListener
{
    public const ENTITY_NAMESPACE = 'Nowo\\VerifactuBundle\\Entity\\';

    /**
     * @param string $tablePrefix Prefix prepended to every bundle table / index / unique constraint / join table name
     *                            ('' = none)
     */
    public function __construct(
        private string $tablePrefix = '',
    ) {
    }

    /**
     * Rewrites the metadata of bundle entities when Doctrine loads it.
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
    {
        $this->apply($event->getClassMetadata());
    }

    /**
     * Applies the prefix to one class metadata (no-op for an empty prefix or a non-bundle entity).
     *
     * @param ClassMetadata<object> $metadata
     */
    public function apply(ClassMetadata $metadata): void
    {
        if ($this->tablePrefix === '' || !str_starts_with($metadata->getName(), self::ENTITY_NAMESPACE)) {
            return;
        }

        $table = [
            'name' => $this->tablePrefix . $metadata->getTableName(),
        ];

        foreach (['indexes', 'uniqueConstraints'] as $key) {
            $definitions = $metadata->table[$key] ?? null;
            if (!is_array($definitions) || $definitions === []) {
                continue;
            }

            $prefixed = [];
            foreach ($definitions as $name => $definition) {
                $prefixed[is_string($name) ? $this->tablePrefix . $name : $name] = $definition;
            }
            $table[$key] = $prefixed;
        }

        $metadata->setPrimaryTable($table);

        foreach ($metadata->associationMappings as $mapping) {
            if ($mapping instanceof ManyToManyOwningSideMapping && !str_starts_with($mapping->joinTable->name, $this->tablePrefix)) {
                $mapping->joinTable->name = $this->tablePrefix . $mapping->joinTable->name;
            }
        }
    }
}
