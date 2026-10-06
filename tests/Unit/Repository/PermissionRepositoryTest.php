<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Tests\Unit\Repository;

use Marko\AdminAuth\Entity\Permission;
use Marko\AdminAuth\Repository\PermissionRepository;
use Marko\AdminAuth\Repository\PermissionRepositoryInterface;
use Marko\Database\Connection\ConnectionInterface;
use Marko\Database\Connection\StatementInterface;
use Marko\Database\Entity\EntityHydrator;
use Marko\Database\Entity\EntityMetadataFactory;
use Marko\Database\Repository\Repository;
use ReflectionClass;
use RuntimeException;

it('constructs PermissionRepository without constructor override', function (): void {
    $reflection = new ReflectionClass(PermissionRepository::class);

    $constructor = $reflection->getConstructor();

    // PermissionRepository should have no constructor of its own — it inherits Repository's
    expect($constructor)->not->toBeNull()
        ->and($constructor->getDeclaringClass()->getName())->not->toBe(PermissionRepository::class);
});

it('creates PermissionRepository extending Repository', function (): void {
    $reflection = new ReflectionClass(PermissionRepository::class);

    expect($reflection->isSubclassOf(Repository::class))->toBeTrue()
        ->and($reflection->implementsInterface(PermissionRepositoryInterface::class))->toBeTrue();
});

it('defines ENTITY_CLASS constant pointing to Permission entity', function (): void {
    $reflection = new ReflectionClass(PermissionRepository::class);

    expect($reflection->hasConstant('ENTITY_CLASS'))->toBeTrue()
        ->and($reflection->getConstant('ENTITY_CLASS'))->toBe(Permission::class);
});

it('can find a permission by id using inherited find method', function (): void {
    $connection = createPermissionMockConnection([
        [
            'id' => 1,
            'key' => 'blog.posts.create',
            'label' => 'Create Posts',
            'group' => 'blog',
            'created_at' => '2024-01-01 00:00:00',
        ],
    ]);
    $metadataFactory = new EntityMetadataFactory();
    $hydrator = new EntityHydrator();

    $repository = new PermissionRepository($connection, $metadataFactory, $hydrator);

    $permission = $repository->find(1);

    expect($permission)->toBeInstanceOf(Permission::class)
        ->and($permission->id)->toBe(1)
        ->and($permission->key)->toBe('blog.posts.create')
        ->and($permission->label)->toBe('Create Posts')
        ->and($permission->group)->toBe('blog');
});

it('finds permissions by key', function (): void {
    $connection = createPermissionMockConnection([
        [
            'id' => 1,
            'key' => 'blog.posts.create',
            'label' => 'Create Posts',
            'group' => 'blog',
            'created_at' => '2024-01-01 00:00:00',
        ],
    ]);
    $metadataFactory = new EntityMetadataFactory();
    $hydrator = new EntityHydrator();

    $repository = new PermissionRepository($connection, $metadataFactory, $hydrator);

    $permission = $repository->findByKey('blog.posts.create');

    expect($permission)->toBeInstanceOf(Permission::class)
        ->and($permission->key)->toBe('blog.posts.create');
});

it('provides findByKey convenience method for key lookups', function (): void {
    $connection = createPermissionMockConnection([
        [
            'id' => 1,
            'key' => 'blog.posts.create',
            'label' => 'Create Posts',
            'group' => 'blog',
            'created_at' => '2024-01-01 00:00:00',
        ],
    ]);
    $metadataFactory = new EntityMetadataFactory();
    $hydrator = new EntityHydrator();

    $repository = new PermissionRepository($connection, $metadataFactory, $hydrator);

    $permission = $repository->findByKey('blog.posts.create');

    expect($permission)->toBeInstanceOf(Permission::class)
        ->and($permission->key)->toBe('blog.posts.create');
});

it('finds permissions by group', function (): void {
    $queryHistory = [];
    $connection = createPermissionMockConnectionWithHistory(
        [
            [
                'id' => 1,
                'key' => 'blog.posts.create',
                'label' => 'Create Posts',
                'group' => 'blog',
                'created_at' => '2024-01-01 00:00:00',
            ],
        ],
        $queryHistory,
    );
    $metadataFactory = new EntityMetadataFactory();
    $hydrator = new EntityHydrator();

    $repository = new PermissionRepository($connection, $metadataFactory, $hydrator);

    $permissions = $repository->findByGroup('blog');

    expect($permissions)->toHaveCount(1)
        ->and($permissions[0])->toBeInstanceOf(Permission::class)
        ->and($permissions[0]->group)->toBe('blog');
});

it('provides findByGroup method for group lookups', function (): void {
    $queryHistory = [];
    $connection = createPermissionMockConnectionWithHistory(
        [
            [
                'id' => 1,
                'key' => 'blog.posts.create',
                'label' => 'Create Posts',
                'group' => 'blog',
                'created_at' => '2024-01-01 00:00:00',
            ],
            [
                'id' => 2,
                'key' => 'blog.posts.edit',
                'label' => 'Edit Posts',
                'group' => 'blog',
                'created_at' => '2024-01-01 00:00:00',
            ],
        ],
        $queryHistory,
    );
    $metadataFactory = new EntityMetadataFactory();
    $hydrator = new EntityHydrator();

    $repository = new PermissionRepository($connection, $metadataFactory, $hydrator);

    $permissions = $repository->findByGroup('blog');

    expect($permissions)->toHaveCount(2)
        ->and($permissions[0])->toBeInstanceOf(Permission::class)
        ->and($permissions[0]->group)->toBe('blog')
        ->and($queryHistory[0]['sql'])->toContain('"group" = ?')
        ->and($queryHistory[0]['bindings'])->toContain('blog');
});

it('contains no driver-specific identifier quoting in PermissionRepository', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Repository/PermissionRepository.php');

    expect($source)->not->toContain('`');
});

// Helper functions

function createPermissionMockConnection(
    array $queryResult = [],
): ConnectionInterface {
    return createPermissionMockConnectionWithHistory($queryResult, $unused);
}

/**
 * @param array<array<string, mixed>> $queryResult
 * @param array<array{sql: string, bindings: array<mixed>}>|null $queryHistory
 */
function createPermissionMockConnectionWithHistory(
    array $queryResult = [],
    ?array &$queryHistory = null,
): ConnectionInterface {
    $queryHistory ??= [];

    return new class ($queryResult, $queryHistory) implements ConnectionInterface
    {
        /**
         * @param array<array<string, mixed>> $queryResult
         * @param array<array{sql: string, bindings: array<mixed>}> $queryHistory
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
         * @param array<mixed> $bindings
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
         * @param array<mixed> $bindings
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
