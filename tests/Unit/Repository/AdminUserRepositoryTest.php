<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Tests\Unit\Repository;

use Marko\AdminAuth\Entity\AdminUser;
use Marko\AdminAuth\Entity\Role;
use Marko\AdminAuth\Repository\AdminUserRepository;
use Marko\AdminAuth\Repository\AdminUserRepositoryInterface;
use Marko\AdminAuth\Tests\Fixtures\SqlitePermissionConnection;
use Marko\Database\Connection\ConnectionInterface;
use Marko\Database\Connection\StatementInterface;
use Marko\Database\Entity\EntityHydrator;
use Marko\Database\Entity\EntityMetadataFactory;
use Marko\Database\Repository\Repository;
use PDOException;
use ReflectionClass;
use RuntimeException;

it('creates AdminUserRepository extending Repository', function (): void {
    $reflection = new ReflectionClass(AdminUserRepository::class);

    expect($reflection->isSubclassOf(Repository::class))->toBeTrue()
        ->and($reflection->implementsInterface(AdminUserRepositoryInterface::class))->toBeTrue();
});

it('defines ENTITY_CLASS constant pointing to AdminUser entity', function (): void {
    $reflection = new ReflectionClass(AdminUserRepository::class);

    expect($reflection->hasConstant('ENTITY_CLASS'))->toBeTrue()
        ->and($reflection->getConstant('ENTITY_CLASS'))->toBe(AdminUser::class);
});

it('can find an admin user by id using inherited find method', function (): void {
    $connection = createAdminUserMockConnection([
        [
            'id' => 1,
            'email' => 'admin@example.com',
            'password' => 'hashed_password',
            'name' => 'Admin User',
            'remember_token' => null,
            'is_active' => '1',
            'created_at' => '2024-01-01 00:00:00',
            'updated_at' => '2024-01-01 00:00:00',
        ],
    ]);
    $metadataFactory = new EntityMetadataFactory();
    $hydrator = new EntityHydrator();

    $repository = new AdminUserRepository($connection, $metadataFactory, $hydrator);

    $user = $repository->find(1);

    expect($user)->toBeInstanceOf(AdminUser::class)
        ->and($user->id)->toBe(1)
        ->and($user->email)->toBe('admin@example.com')
        ->and($user->name)->toBe('Admin User');
});

it('provides findByEmail convenience method for email lookups', function (): void {
    $connection = createAdminUserMockConnection([
        [
            'id' => 1,
            'email' => 'admin@example.com',
            'password' => 'hashed_password',
            'name' => 'Admin User',
            'remember_token' => null,
            'is_active' => '1',
            'created_at' => '2024-01-01 00:00:00',
            'updated_at' => '2024-01-01 00:00:00',
        ],
    ]);
    $metadataFactory = new EntityMetadataFactory();
    $hydrator = new EntityHydrator();

    $repository = new AdminUserRepository($connection, $metadataFactory, $hydrator);

    $user = $repository->findByEmail('admin@example.com');

    expect($user)->toBeInstanceOf(AdminUser::class)
        ->and($user->email)->toBe('admin@example.com');
});

it('looks up findByEmail with the lowercased email', function (): void {
    $queryHistory = [];
    $connection = createAdminUserMockConnectionWithHistory([], $queryHistory);
    $metadataFactory = new EntityMetadataFactory();

    $repository = new AdminUserRepository($connection, $metadataFactory, new EntityHydrator());
    $repository->findByEmail('Mark@Example.COM');

    expect($queryHistory[0]['bindings'])->toBe(['mark@example.com']);
});

it('lowercases the email when saving an admin user', function (): void {
    $queryHistory = [];
    $connection = createAdminUserMockConnectionWithHistory([], $queryHistory);
    $metadataFactory = new EntityMetadataFactory();
    $repository = new AdminUserRepository($connection, $metadataFactory, new EntityHydrator($metadataFactory));

    $user = new AdminUser();
    $user->email = 'Mark@Example.COM';
    $user->password = 'hash';
    $user->name = 'Mark';
    $repository->save($user);

    expect($user->email)->toBe('mark@example.com')
        ->and($queryHistory[0]['sql'])->toStartWith('INSERT')
        ->and($queryHistory[0]['bindings'])->toContain('mark@example.com')
        ->and($queryHistory[0]['bindings'])->not->toContain('Mark@Example.COM');
});

it('lowercases emails in insertBatch', function (): void {
    $queryHistory = [];
    $connection = createAdminUserMockConnectionWithHistory([], $queryHistory);
    $metadataFactory = new EntityMetadataFactory();
    $repository = new AdminUserRepository($connection, $metadataFactory, new EntityHydrator($metadataFactory));

    $first = new AdminUser();
    $first->email = 'First@Example.com';
    $first->password = 'hash';
    $first->name = 'First';
    $second = new AdminUser();
    $second->email = 'SECOND@example.com';
    $second->password = 'hash';
    $second->name = 'Second';
    $repository->insertBatch([$first, $second]);

    $insert = array_find($queryHistory, fn (array $entry): bool => str_starts_with($entry['sql'], 'INSERT'));

    expect($first->email)->toBe('first@example.com')
        ->and($second->email)->toBe('second@example.com')
        ->and($insert['bindings'])->toContain('first@example.com')
        ->and($insert['bindings'])->toContain('second@example.com');
});

it('lowercases multibyte characters in the email', function (): void {
    $queryHistory = [];
    $connection = createAdminUserMockConnectionWithHistory([], $queryHistory);
    $metadataFactory = new EntityMetadataFactory();
    $repository = new AdminUserRepository($connection, $metadataFactory, new EntityHydrator($metadataFactory));

    $user = new AdminUser();
    $user->email = 'ÉLODIE@Example.com';
    $user->password = 'hash';
    $user->name = 'Élodie';
    $repository->save($user);
    $repository->findByEmail('Élodie@EXAMPLE.com');

    expect($user->email)->toBe('élodie@example.com')
        ->and($queryHistory[1]['bindings'])->toBe(['élodie@example.com']);
});

it('loads roles for a user via getRolesForUser', function (): void {
    $queryHistory = [];
    $connection = createAdminUserMockConnectionWithHistory(
        [
            [
                'id' => 1,
                'name' => 'Administrator',
                'slug' => 'admin',
                'description' => 'Full access',
                'is_super_admin' => '1',
                'created_at' => '2024-01-01 00:00:00',
                'updated_at' => '2024-01-01 00:00:00',
            ],
            [
                'id' => 2,
                'name' => 'Editor',
                'slug' => 'editor',
                'description' => 'Can edit content',
                'is_super_admin' => '0',
                'created_at' => '2024-01-01 00:00:00',
                'updated_at' => '2024-01-01 00:00:00',
            ],
        ],
        $queryHistory,
    );
    $metadataFactory = new EntityMetadataFactory();
    $hydrator = new EntityHydrator();

    $repository = new AdminUserRepository($connection, $metadataFactory, $hydrator);

    $roles = $repository->getRolesForUser(1);

    expect($roles)->toHaveCount(2)
        ->and($roles[0])->toBeInstanceOf(Role::class)
        ->and($roles[0]->name)->toBe('Administrator')
        ->and($roles[0]->slug)->toBe('admin')
        ->and($roles[1]->name)->toBe('Editor')
        ->and($queryHistory[0]['sql'])->toContain('admin_user_roles')
        ->and($queryHistory[0]['sql'])->toContain('user_id = ?')
        ->and($queryHistory[0]['bindings'])->toBe([1]);
});

it('replaces a user\'s roles with the new set', function (): void {
    $connection = new SqlitePermissionConnection();
    $repository = new AdminUserRepository($connection, new EntityMetadataFactory(), new EntityHydrator());
    $repository->syncRoles(1, [1, 2]);
    $repository->syncRoles(2, [5]);

    $repository->syncRoles(1, [10, 20]);

    expect($connection->roleIdsForUser(1))->toBe([10, 20])
        ->and($connection->roleIdsForUser(2))->toBe([5]);
});

it('removes every role when given an empty role id list', function (): void {
    $connection = new SqlitePermissionConnection();
    $repository = new AdminUserRepository($connection, new EntityMetadataFactory(), new EntityHydrator());
    $repository->syncRoles(1, [1, 2]);
    $connection->log = [];

    $repository->syncRoles(1, []);

    expect($connection->statements('INSERT'))->toBe([])
        ->and($connection->roleIdsForUser(1))->toBe([]);
});

it('inserts all new roles in a single multi-row insert', function (): void {
    $queryHistory = [];
    $connection = createAdminUserMockConnectionWithHistory([], $queryHistory);
    $repository = new AdminUserRepository($connection, new EntityMetadataFactory(), new EntityHydrator());

    $repository->syncRoles(1, [10, 20, 30]);

    $inserts = array_values(array_filter(
        $queryHistory,
        fn (array $entry): bool => str_contains($entry['sql'], 'INSERT'),
    ));

    expect($queryHistory[0]['sql'])->toBe('DELETE FROM admin_user_roles WHERE user_id = ?')
        ->and($queryHistory[0]['bindings'])->toBe([1])
        ->and($inserts)->toHaveCount(1)
        ->and($inserts[0]['sql'])->toBe(
            'INSERT INTO admin_user_roles (user_id, role_id) VALUES (?, ?), (?, ?), (?, ?)',
        )
        ->and($inserts[0]['bindings'])->toBe([1, 10, 1, 20, 1, 30]);
});

it('wraps the delete and insert in one transaction when the connection supports transactions', function (): void {
    $connection = new SqlitePermissionConnection();
    $repository = new AdminUserRepository($connection, new EntityMetadataFactory(), new EntityHydrator());

    $repository->syncRoles(1, [10, 20]);

    expect($connection->log)->toBe([
        'BEGIN',
        'DELETE FROM admin_user_roles WHERE user_id = ?',
        'INSERT INTO admin_user_roles (user_id, role_id) VALUES (?, ?), (?, ?)',
        'COMMIT',
    ]);
});

it('rolls back and leaves roles unchanged when an insert fails mid-sync', function (): void {
    $connection = new SqlitePermissionConnection();
    $repository = new AdminUserRepository($connection, new EntityMetadataFactory(), new EntityHydrator());
    $repository->syncRoles(1, [1, 2]);
    $connection->failOn = 'INSERT INTO admin_user_roles';

    expect(fn () => $repository->syncRoles(1, [10, 20]))->toThrow(RuntimeException::class)
        ->and($connection->roleIdsForUser(1))->toBe([1, 2])
        ->and($connection->inTransaction())->toBeFalse();
});

it('keeps the previous roles when the new set repeats a role id', function (): void {
    $connection = new SqlitePermissionConnection();
    $repository = new AdminUserRepository($connection, new EntityMetadataFactory(), new EntityHydrator());
    $repository->syncRoles(1, [1, 2]);

    expect(fn () => $repository->syncRoles(1, [10, 10]))->toThrow(PDOException::class)
        ->and($connection->roleIdsForUser(1))->toBe([1, 2]);
});

it('rolls back only its own changes when it fails inside an outer transaction', function (): void {
    $connection = new SqlitePermissionConnection();
    $repository = new AdminUserRepository($connection, new EntityMetadataFactory(), new EntityHydrator());
    $repository->syncRoles(1, [1, 2]);

    $connection->beginTransaction();
    $repository->syncRoles(2, [5]);
    $connection->failOn = 'INSERT INTO admin_user_roles';

    try {
        $repository->syncRoles(1, [10, 20]);
    } catch (RuntimeException) {
        // The caller decides to keep its own work.
    }

    expect($connection->inTransaction())->toBeTrue()
        ->and($connection->roleIdsForUser(1))->toBe([1, 2])
        ->and($connection->roleIdsForUser(2))->toBe([5]);

    $connection->rollback();
});

it('lets the outer transaction commit after a failed sync is caught', function (): void {
    $connection = new SqlitePermissionConnection();
    $repository = new AdminUserRepository($connection, new EntityMetadataFactory(), new EntityHydrator());
    $repository->syncRoles(1, [1, 2]);

    $connection->beginTransaction();
    $repository->syncRoles(2, [5]);
    $connection->failOn = 'INSERT INTO admin_user_roles';

    try {
        $repository->syncRoles(1, [10, 20]);
    } catch (RuntimeException) {
        // The caller decides to keep its own work.
    }

    $connection->commit();

    expect($connection->inTransaction())->toBeFalse()
        ->and($connection->roleIdsForUser(1))->toBe([1, 2])
        ->and($connection->roleIdsForUser(2))->toBe([5]);
});

it('still syncs roles when the connection does not support transactions', function (): void {
    $queryHistory = [];
    $connection = createAdminUserMockConnectionWithHistory([], $queryHistory);
    $repository = new AdminUserRepository($connection, new EntityMetadataFactory(), new EntityHydrator());

    $repository->syncRoles(1, [10, 20]);

    expect(array_column($queryHistory, 'sql'))->toBe([
        'DELETE FROM admin_user_roles WHERE user_id = ?',
        'INSERT INTO admin_user_roles (user_id, role_id) VALUES (?, ?), (?, ?)',
    ]);
});

// Helper functions

function createAdminUserMockConnection(
    array $queryResult = [],
): ConnectionInterface {
    return createAdminUserMockConnectionWithHistory($queryResult, $unused);
}

/**
 * @param array<array<string, mixed>> $queryResult
 * @param array<array{sql: string, bindings: array}>|null $queryHistory
 */
function createAdminUserMockConnectionWithHistory(
    array $queryResult = [],
    ?array &$queryHistory = null,
): ConnectionInterface {
    $queryHistory ??= [];

    return new class ($queryResult, $queryHistory) implements ConnectionInterface
    {
        /**
         * @param array<array<string, mixed>> $queryResult
         * @param array<array{sql: string, bindings: array}> $queryHistory
         */
        public function __construct(
            private readonly array $queryResult,
            private array &$queryHistory,
        ) {}

        public function connect(): void {}

        public function disconnect(): void {}

        public function isConnected(): bool
        {
            return true;
        }

        /**
         * @param string $sql
         * @param array $bindings
         * @return array<array<string, mixed>>
         */
        public function query(
            string $sql,
            array $bindings = [],
        ): array {
            $this->queryHistory[] = ['sql' => $sql, 'bindings' => $bindings];

            return $this->queryResult;
        }

        /**
         * @param string $sql
         * @param array $bindings
         * @return int
         */
        public function execute(
            string $sql,
            array $bindings = [],
        ): int {
            $this->queryHistory[] = ['sql' => $sql, 'bindings' => $bindings];

            return 1;
        }

        public function prepare(
            string $sql,
        ): StatementInterface {
            throw new RuntimeException('Not implemented');
        }

        public function lastInsertId(): int
        {
            return 1;
        }

        public function driverName(): string
        {
            return 'sqlite';
        }

        public function supportsReturning(): bool
        {
            return false;
        }

        public function quoteIdentifier(
            string $identifier,
        ): string {
            return '"' . str_replace('"', '""', $identifier) . '"';
        }
    };
}
