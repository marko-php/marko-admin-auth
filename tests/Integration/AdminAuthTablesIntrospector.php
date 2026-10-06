<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Tests\Integration;

use Marko\Database\Introspection\IntrospectorInterface;
use Marko\Database\Schema\Table;

/**
 * A driver introspector that lists only the admin-auth tables (and the migrations table).
 *
 * The integration tests share marko_test with the database-mysql and database-pgsql suites. db:migrate diffs the
 * entities against every table it can see, so a table another suite left behind would show up as a table to drop.
 * Listing only the admin-auth tables keeps the diff to what admin-auth owns; everything else is the real driver.
 */
readonly class AdminAuthTablesIntrospector implements IntrospectorInterface
{
    public function __construct(
        private IntrospectorInterface $introspector,
    ) {}

    public function getTables(): array
    {
        return array_values(array_filter(
            $this->introspector->getTables(),
            fn (string $table): bool => in_array($table, [...AdminAuthSchema::TABLES, 'migrations'], true),
        ));
    }

    public function getTable(
        string $name,
    ): ?Table {
        return $this->introspector->getTable($name);
    }

    public function tableExists(
        string $name,
    ): bool {
        return $this->introspector->tableExists($name);
    }

    public function getColumns(
        string $table,
    ): array {
        return $this->introspector->getColumns($table);
    }

    public function getIndexes(
        string $table,
    ): array {
        return $this->introspector->getIndexes($table);
    }

    public function getForeignKeys(
        string $table,
    ): array {
        return $this->introspector->getForeignKeys($table);
    }

    public function getPrimaryKey(
        string $table,
    ): array {
        return $this->introspector->getPrimaryKey($table);
    }
}
