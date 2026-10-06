<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Tests\Integration\MySql;

use Marko\AdminAuth\Entity\Permission;
use Marko\AdminAuth\Entity\Role;
use Marko\AdminAuth\PermissionRegistry;
use Marko\AdminAuth\Tests\Integration\AdminAuthSchema;
use Marko\Database\Exceptions\UniqueConstraintViolationException;
use Marko\Database\MySql\Connection\MySqlConnection;
use Marko\Database\MySql\Sql\MySqlGenerator;
use Marko\Database\MySql\Tests\Fixtures\IntegrationDatabase;

/*
 * The role_permissions and admin_user_roles pivots on a real MySQL server; CI also runs this directory against
 * MariaDB 11.8 and 10.11. Both pivots are created from their entities (RolePermission, AdminUserRole) through
 * SchemaBuilder and MySqlGenerator, so the foreign-key cascades and unique indexes tested here are the ones
 * db:migrate builds. Set MARKO_TEST_MYSQL_HOST (and optionally _PORT, _DATABASE, _USERNAME, _PASSWORD) to enable;
 * the tests skip otherwise. The tests create and drop the admin-auth tables.
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

    $registry = new PermissionRegistry();
    $registry->register('blog.posts.view', 'View Posts', 'blog');
    $registry->register('blog.posts.edit', 'Edit Posts', 'blog');
    $registry->register('users.view', 'View Users', 'users');

    $this->permissions = AdminAuthSchema::permissionRepository($this->connection);
    $this->permissions->syncFromRegistry($registry);
    $this->roles = AdminAuthSchema::roleRepository($this->connection);
    $this->users = AdminAuthSchema::adminUserRepository($this->connection);
    $this->permissionId = fn (string $key): int => (int) $this->permissions->findByKey($key)?->id;
    $this->keys = fn (array $permissions): array => array_map(
        fn (Permission $permission): string => $permission->key,
        $permissions,
    );
});

afterEach(function (): void {
    if (isset($this->connection)) {
        AdminAuthSchema::drop($this->connection);
        $this->connection->disconnect();
    }
});

describe('admin-auth pivots on MySQL', function (): void {
    it('syncs and reads role permissions', function (): void {
        $role = AdminAuthSchema::roleWith($this->connection, 'editor', ['blog.posts.view', 'blog.posts.edit']);
        $before = ($this->keys)($this->roles->getPermissionsForRole((int) $role->id));

        $this->roles->syncPermissions((int) $role->id, [($this->permissionId)('users.view')]);

        expect($before)->toEqualCanonicalizing(['blog.posts.view', 'blog.posts.edit'])
            ->and(($this->keys)($this->roles->getPermissionsForRole((int) $role->id)))->toBe(['users.view']);
    });

    it('reads the distinct permissions of several roles', function (): void {
        $editor = AdminAuthSchema::roleWith($this->connection, 'editor', ['blog.posts.view', 'blog.posts.edit']);
        $viewer = AdminAuthSchema::roleWith($this->connection, 'viewer', ['blog.posts.view', 'users.view']);

        $permissions = $this->roles->getPermissionsForRoles([(int) $editor->id, (int) $viewer->id]);

        expect(($this->keys)($permissions))
            ->toEqualCanonicalizing(['blog.posts.view', 'blog.posts.edit', 'users.view']);
    });

    it('syncs and reads user roles', function (): void {
        $editor = AdminAuthSchema::roleWith($this->connection, 'editor', []);
        $viewer = AdminAuthSchema::roleWith($this->connection, 'viewer', []);
        $user = AdminAuthSchema::userWith(
            $this->connection,
            'editor@example.com',
            [(int) $editor->id, (int) $viewer->id],
        );
        $slugs = fn (): array => array_map(
            fn (Role $role): string => $role->slug,
            $this->users->getRolesForUser((int) $user->id),
        );
        $before = $slugs();

        $this->users->syncRoles((int) $user->id, [(int) $viewer->id]);

        expect($before)->toEqualCanonicalizing(['editor', 'viewer'])
            ->and($slugs())->toBe(['viewer']);
    });

    it('cascades role, permission and user deletes to the pivots', function (): void {
        $editor = AdminAuthSchema::roleWith($this->connection, 'editor', ['blog.posts.view', 'users.view']);
        $viewer = AdminAuthSchema::roleWith($this->connection, 'viewer', ['blog.posts.view']);
        $first = AdminAuthSchema::userWith($this->connection, 'first@example.com', [(int) $editor->id]);
        $second = AdminAuthSchema::userWith($this->connection, 'second@example.com', [(int) $viewer->id]);
        $usersViewId = ($this->permissionId)('users.view');

        $this->roles->delete($editor);
        $this->permissions->delete($this->permissions->findByKey('blog.posts.view'));
        $this->users->delete($second);

        $count = fn (string $table, string $column, int $id): int => AdminAuthSchema::pivotCount(
            $this->connection,
            $table,
            $column,
            $id,
        );

        expect($count('role_permissions', 'role_id', (int) $editor->id))->toBe(0)
            ->and($count('role_permissions', 'permission_id', $usersViewId))->toBe(0)
            ->and($count('role_permissions', 'role_id', (int) $viewer->id))->toBe(0)
            ->and($count('admin_user_roles', 'user_id', (int) $first->id))->toBe(0)
            ->and($count('admin_user_roles', 'user_id', (int) $second->id))->toBe(0)
            ->and($count('admin_user_roles', 'role_id', (int) $viewer->id))->toBe(0)
            ->and($this->roles->findBySlug('viewer'))->not->toBeNull();
    });

    it('rejects a duplicate role permission and keeps the earlier assignments', function (): void {
        $role = AdminAuthSchema::roleWith($this->connection, 'editor', ['blog.posts.edit']);
        $viewId = ($this->permissionId)('blog.posts.view');

        expect(fn () => $this->roles->syncPermissions((int) $role->id, [$viewId, $viewId]))
            ->toThrow(UniqueConstraintViolationException::class)
            ->and(($this->keys)($this->roles->getPermissionsForRole((int) $role->id)))->toBe(['blog.posts.edit']);
    });

    it('rejects a duplicate user role', function (): void {
        $role = AdminAuthSchema::roleWith($this->connection, 'editor', []);
        $user = AdminAuthSchema::userWith($this->connection, 'editor@example.com', []);

        expect(fn () => $this->users->syncRoles((int) $user->id, [(int) $role->id, (int) $role->id]))
            ->toThrow(UniqueConstraintViolationException::class);
    });
});
