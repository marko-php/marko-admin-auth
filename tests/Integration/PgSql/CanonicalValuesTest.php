<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Tests\Integration\PgSql;

use Marko\AdminAuth\Entity\AdminUser;
use Marko\AdminAuth\Entity\Role;
use Marko\AdminAuth\Exceptions\AdminAuthException;
use Marko\AdminAuth\PermissionRegistry;
use Marko\AdminAuth\Tests\Integration\AdminAuthSchema;
use Marko\AdminAuth\Tests\Integration\NativePasswordHasher;
use Marko\Database\Exceptions\UniqueConstraintViolationException;
use Marko\Database\PgSql\Connection\PgSqlConnection;
use Marko\Database\PgSql\Sql\PgSqlGenerator;
use Marko\Database\PgSql\Tests\Fixtures\IntegrationDatabase;

/*
 * The admin-auth unique string columns (permissions.key, roles.slug, admin_users.email) against a real PostgreSQL
 * server. PostgreSQL's default collation compares exactly, while MySQL/MariaDB compare case-insensitively, so these
 * tests and their MySql twins assert the same outcome on every driver: keys and slugs are validated lowercase
 * identifiers and emails are lowercased before SQL, so the collation never decides.
 *
 * Settings come from the database-pgsql IntegrationDatabase fixture (MARKO_TEST_PGSQL_*); the tests skip without
 * a host, and fail instead with MARKO_INTEGRATION_REQUIRED set (CI). Part of the integration-services group.
 */

pest()->group('integration-services');

beforeEach(function (): void {
    $config = IntegrationDatabase::config();

    if ($config === null) {
        $this->markTestSkipped(IntegrationDatabase::SKIP_REASON);
    }

    $this->connection = new PgSqlConnection($config);
    AdminAuthSchema::drop($this->connection);
    AdminAuthSchema::create($this->connection, new PgSqlGenerator());

    $this->permissions = AdminAuthSchema::permissionRepository($this->connection);
});

afterEach(function (): void {
    if (isset($this->connection)) {
        AdminAuthSchema::drop($this->connection);
        $this->connection->disconnect();
    }
});

describe('canonical admin-auth values on PostgreSQL', function (): void {
    it('repairs a stored case variant on re-sync without a duplicate or a failure', function (): void {
        $legacyId = AdminAuthSchema::legacyPermission($this->connection, 'Posts.Edit', 'Edit Posts', 'Posts');
        $role = AdminAuthSchema::roleWith($this->connection, 'editor', []);
        AdminAuthSchema::roleRepository($this->connection)->syncPermissions((int) $role->id, [$legacyId]);
        $registry = new PermissionRegistry();
        $registry->register('posts.edit', 'Edit Posts', 'posts');

        $first = $this->permissions->syncFromRegistry($registry);
        $second = $this->permissions->syncFromRegistry($registry);

        expect($first->created)->toBe([])
            ->and($first->updated)->toBe(['posts.edit'])
            ->and($first->unregistered)->toBe([])
            ->and($second->created)->toBe([])
            ->and($second->updated)->toBe([])
            ->and($second->unregistered)->toBe([])
            ->and(AdminAuthSchema::rowCount($this->connection, 'permissions'))->toBe(1)
            ->and($this->permissions->findByKey('posts.edit')?->id)->toBe($legacyId)
            ->and($this->permissions->findByKey('Posts.Edit'))->toBeNull()
            ->and(AdminAuthSchema::assignmentCount($this->connection, $legacyId))->toBe(1);
    });

    it('logs in with a different-case email', function (): void {
        $hasher = new NativePasswordHasher();
        $user = new AdminUser();
        $user->email = 'Mark@Example.com';
        $user->password = $hasher->hash('secret');
        $user->name = 'Mark';
        AdminAuthSchema::adminUserRepository($this->connection)->save($user);
        $provider = AdminAuthSchema::userProvider($this->connection, $hasher);

        $retrieved = $provider->retrieveByCredentials(['email' => 'MARK@example.COM', 'password' => 'secret']);

        expect($user->email)->toBe('mark@example.com')
            ->and($retrieved)->toBeInstanceOf(AdminUser::class)
            ->and($retrieved?->getAuthIdentifier())->toBe($user->id)
            ->and($provider->validateCredentials($retrieved, ['password' => 'secret']))->toBeTrue();
    });

    it('rejects a second admin whose email differs only in case', function (): void {
        AdminAuthSchema::userWith($this->connection, 'mark@example.com', []);

        expect(fn () => AdminAuthSchema::userWith($this->connection, 'Mark@Example.com', []))
            ->toThrow(UniqueConstraintViolationException::class)
            ->and(AdminAuthSchema::rowCount($this->connection, 'admin_users'))->toBe(1);
    });

    it('rejects a role with a non-canonical slug before it reaches the database', function (): void {
        $roles = AdminAuthSchema::roleRepository($this->connection);
        AdminAuthSchema::roleWith($this->connection, 'editor', []);
        $role = new Role();
        $role->name = 'Editor';
        $role->slug = 'Editor';

        expect(fn () => $roles->save($role))
            ->toThrow(AdminAuthException::class, "Role slug 'Editor' is not a valid role slug")
            ->and(fn () => $roles->isSlugUnique('Editor'))->toThrow(AdminAuthException::class)
            ->and($roles->isSlugUnique('editor'))->toBeFalse()
            ->and($roles->findBySlug('Editor'))->toBeNull()
            ->and($roles->findBySlug('editor')?->slug)->toBe('editor')
            ->and(AdminAuthSchema::rowCount($this->connection, 'roles'))->toBe(1);
    });
});
