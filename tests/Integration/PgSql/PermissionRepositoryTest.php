<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Tests\Integration\PgSql;

use Marko\AdminAuth\Entity\Permission;
use Marko\AdminAuth\PermissionRegistry;
use Marko\AdminAuth\Repository\PermissionRepository;
use Marko\Database\Diff\SchemaDiff;
use Marko\Database\Entity\EntityHydrator;
use Marko\Database\Entity\EntityMetadataFactory;
use Marko\Database\Entity\SchemaBuilder;
use Marko\Database\PgSql\Connection\PgSqlConnection;
use Marko\Database\PgSql\Sql\PgSqlGenerator;
use Marko\Database\PgSql\Tests\Fixtures\IntegrationDatabase;

/*
 * PermissionRepository against a real PostgreSQL server. The Permission entity has a `group` column, a reserved
 * word in PostgreSQL, so saving and findByGroup() fail with a syntax error unless the repository quotes it (and the
 * MySQL backticks findByGroup() used to hard-code are a syntax error here). The permissions table is created from
 * the entity through
 * SchemaBuilder and PgSqlGenerator. Set MARKO_TEST_PGSQL_HOST (and optionally _PORT, _DATABASE, _USERNAME,
 * _PASSWORD) to enable; the tests skip otherwise. The tests create and drop the permissions table.
 *
 * Settings come from the database-pgsql IntegrationDatabase fixture. With MARKO_INTEGRATION_REQUIRED set (CI), a
 * missing host fails instead of skipping. Part of the integration-services group.
 */

pest()->group('integration-services');

beforeEach(function (): void {
    $config = IntegrationDatabase::config();

    if ($config === null) {
        $this->markTestSkipped(IntegrationDatabase::SKIP_REASON);
    }

    $this->connection = new PgSqlConnection($config);
    $this->connection->execute('DROP TABLE IF EXISTS ' . $this->connection->quoteIdentifier('permissions'));

    $metadataFactory = new EntityMetadataFactory();
    $table = new SchemaBuilder()->build($metadataFactory->parse(Permission::class));

    foreach (new PgSqlGenerator()->generateUp(
        new SchemaDiff(tablesToCreate: [$table->name => $table]),
    ) as $statement) {
        $this->connection->execute($statement);
    }

    $this->permissions = new PermissionRepository(
        $this->connection,
        $metadataFactory,
        new EntityHydrator($metadataFactory),
    );
});

afterEach(function (): void {
    if (isset($this->connection)) {
        $this->connection->execute('DROP TABLE IF EXISTS ' . $this->connection->quoteIdentifier('permissions'));
        $this->connection->disconnect();
    }
});

describe('PermissionRepository on PostgreSQL', function (): void {
    it('saves permissions and finds them by key and by group', function (): void {
        foreach ([['blog.posts.create', 'blog'], ['blog.posts.edit', 'blog'], ['users.view', 'users']] as [$key, $group]) {
            $permission = new Permission();
            $permission->key = $key;
            $permission->label = ucfirst($key);
            $permission->group = $group;
            $this->permissions->save($permission);
        }

        $blog = $this->permissions->findByGroup('blog');

        expect($this->permissions->findByKey('users.view')?->group)->toBe('users')
            ->and($this->permissions->findByKey('missing'))->toBeNull()
            ->and($blog)->toHaveCount(2)
            ->and(array_map(fn (Permission $permission): string => $permission->key, $blog))
            ->toEqualCanonicalizing(['blog.posts.create', 'blog.posts.edit']);
    });

    it('syncs permissions from the registry without duplicating existing ones', function (): void {
        $registry = new PermissionRegistry();
        $registry->register('blog.posts.create', 'Create Posts', 'blog');
        $registry->register('blog.posts.edit', 'Edit Posts', 'blog');

        $this->permissions->syncFromRegistry($registry);
        $registry->register('users.view', 'View Users', 'users');
        $this->permissions->syncFromRegistry($registry);

        expect($this->permissions->count())->toBe(3)
            ->and($this->permissions->findByGroup('blog'))->toHaveCount(2)
            ->and($this->permissions->findByKey('users.view')?->label)->toBe('View Users');
    });
});
