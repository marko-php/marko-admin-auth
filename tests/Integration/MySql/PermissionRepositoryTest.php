<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Tests\Integration\MySql;

use Marko\AdminAuth\Entity\AdminUser;
use Marko\AdminAuth\Entity\Permission;
use Marko\AdminAuth\PermissionRegistry;
use Marko\AdminAuth\Repository\UnregisteredPermission;
use Marko\AdminAuth\Tests\Integration\AdminAuthSchema;
use Marko\Authentication\Contracts\PasswordHasherInterface;
use Marko\Database\MySql\Connection\MySqlConnection;
use Marko\Database\MySql\Sql\MySqlGenerator;
use Marko\Database\MySql\Tests\Fixtures\IntegrationDatabase;

/*
 * PermissionRepository against a real MySQL server; CI also runs this directory against MariaDB. The Permission
 * entity has `key` and `group` columns, both reserved words in MySQL, so every repository call here fails with a
 * syntax error unless the repository quotes them. The admin-auth tables are created from the entities through
 * SchemaBuilder and MySqlGenerator (see AdminAuthSchema). Set MARKO_TEST_MYSQL_HOST (and optionally _PORT,
 * _DATABASE, _USERNAME, _PASSWORD) to enable; the tests skip otherwise. The tests create and drop the admin-auth
 * tables.
 *
 * Settings come from the database-mysql IntegrationDatabase fixture. With MARKO_INTEGRATION_REQUIRED set (CI), a
 * missing host fails instead of skipping. Part of the integration-services group.
 */

pest()->group('integration-services');

beforeEach(function (): void {
    $config = IntegrationDatabase::config();

    if ($config === null) {
        $this->markTestSkipped(IntegrationDatabase::SKIP_REASON);
    }

    $this->connection = new MySqlConnection($config);
    AdminAuthSchema::drop($this->connection);
    AdminAuthSchema::create($this->connection, new MySqlGenerator());

    $this->permissions = AdminAuthSchema::permissionRepository($this->connection);
});

afterEach(function (): void {
    if (isset($this->connection)) {
        AdminAuthSchema::drop($this->connection);
        $this->connection->disconnect();
    }
});

describe('PermissionRepository on MySQL', function (): void {
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

    it('updates labels and groups and reports unregistered permissions with role counts', function (): void {
        $before = new PermissionRegistry();
        $before->register('blog.posts.view', 'View Posts', 'blog');
        $before->register('legacy.export', 'Export', 'legacy');
        $this->permissions->syncFromRegistry($before);
        AdminAuthSchema::roleWith($this->connection, 'editor', ['blog.posts.view', 'legacy.export']);
        AdminAuthSchema::roleWith($this->connection, 'auditor', ['legacy.export']);

        $after = new PermissionRegistry();
        $after->register('blog.posts.view', 'Read Posts', 'content');
        $result = $this->permissions->syncFromRegistry($after);

        $exportId = (int) $this->permissions->findByKey('legacy.export')?->id;

        expect($result->updated)->toBe(['blog.posts.view'])
            ->and($result->created)->toBe([])
            ->and($result->unregistered)->toEqual([
                new UnregisteredPermission(
                    id: $exportId,
                    key: 'legacy.export',
                    label: 'Export',
                    group: 'legacy',
                    roleCount: 2,
                ),
            ])
            ->and($this->permissions->findByKey('blog.posts.view')?->label)->toBe('Read Posts')
            ->and($this->permissions->findByKey('blog.posts.view')?->group)->toBe('content')
            ->and($this->permissions->count())->toBe(2);
    });

    it('prunes unregistered permissions and their role assignments but keeps wildcard grants', function (): void {
        $registry = new PermissionRegistry();
        $registry->register('blog.posts.view', 'View Posts', 'blog');
        $registry->register('legacy.export', 'Export', 'legacy');
        $this->permissions->syncFromRegistry($registry);
        AdminAuthSchema::permission($this->connection, 'catalog.*', 'All Catalog', 'catalog');
        AdminAuthSchema::roleWith($this->connection, 'editor', ['blog.posts.view', 'legacy.export', 'catalog.*']);
        $exportId = (int) $this->permissions->findByKey('legacy.export')?->id;
        $wildcardId = (int) $this->permissions->findByKey('catalog.*')?->id;

        $current = new PermissionRegistry();
        $current->register('blog.posts.view', 'View Posts', 'blog');
        $removed = $this->permissions->pruneUnregistered($current);

        expect(array_map(fn (UnregisteredPermission $permission): string => $permission->key, $removed))
            ->toBe(['legacy.export'])
            ->and($this->permissions->findByKey('legacy.export'))->toBeNull()
            ->and(AdminAuthSchema::assignmentCount($this->connection, $exportId))->toBe(0)
            ->and($this->permissions->findByKey('catalog.*'))->not->toBeNull()
            ->and(AdminAuthSchema::assignmentCount($this->connection, $wildcardId))->toBe(1)
            ->and($this->permissions->findByKey('blog.posts.view'))->not->toBeNull();
    });

    it('no longer grants a pruned key to a user loaded through AdminUserProvider', function (): void {
        $registry = new PermissionRegistry();
        $registry->register('blog.posts.view', 'View Posts', 'blog');
        $registry->register('legacy.export', 'Export', 'legacy');
        $this->permissions->syncFromRegistry($registry);
        $role = AdminAuthSchema::roleWith($this->connection, 'editor', ['blog.posts.view', 'legacy.export']);

        $user = new AdminUser();
        $user->email = 'editor@example.com';
        $user->password = 'hash';
        $user->name = 'Editor';
        $users = AdminAuthSchema::adminUserRepository($this->connection);
        $users->save($user);
        $users->syncRoles((int) $user->id, [(int) $role->id]);
        $provider = AdminAuthSchema::userProvider(
            $this->connection,
            $this->createStub(PasswordHasherInterface::class),
        );

        $before = $provider->retrieveById((int) $user->id);

        $current = new PermissionRegistry();
        $current->register('blog.posts.view', 'View Posts', 'blog');
        $this->permissions->pruneUnregistered($current);
        $after = $provider->retrieveById((int) $user->id);

        expect($before)->toBeInstanceOf(AdminUser::class)
            ->and($before->hasPermission('legacy.export'))->toBeTrue()
            ->and($after)->toBeInstanceOf(AdminUser::class)
            ->and($after->hasPermission('legacy.export'))->toBeFalse()
            ->and($after->hasPermission('blog.posts.view'))->toBeTrue();
    });
});
