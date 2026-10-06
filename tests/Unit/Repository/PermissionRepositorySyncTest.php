<?php

declare(strict_types=1);

use Marko\AdminAuth\PermissionRegistry;
use Marko\AdminAuth\Repository\PermissionRepository;
use Marko\AdminAuth\Repository\PermissionRepositoryInterface;
use Marko\AdminAuth\Repository\PermissionSyncResult;
use Marko\AdminAuth\Repository\UnregisteredPermission;
use Marko\AdminAuth\Tests\Fixtures\SqlitePermissionConnection;
use Marko\Database\Entity\EntityHydrator;
use Marko\Database\Entity\EntityMetadataFactory;

function sqlitePermissionRepository(
    SqlitePermissionConnection $connection,
): PermissionRepository {
    $metadataFactory = new EntityMetadataFactory();

    return new PermissionRepository($connection, $metadataFactory, new EntityHydrator($metadataFactory));
}

beforeEach(function (): void {
    $this->connection = new SqlitePermissionConnection();
    $this->repository = sqlitePermissionRepository($this->connection);
    $this->registry = new PermissionRegistry();
});

it('inserts registered permissions missing from the table', function (): void {
    $this->connection->addPermission('blog.posts.view', 'View Posts', 'blog');
    $this->registry->register('blog.posts.view', 'View Posts', 'blog');
    $this->registry->register('blog.posts.edit', 'Edit Posts', 'blog');

    $result = $this->repository->syncFromRegistry($this->registry);

    expect($result->created)->toBe(['blog.posts.edit'])
        ->and($result->registeredCount)->toBe(2)
        ->and($this->connection->permissions())->toBe([
            'blog.posts.edit' => ['label' => 'Edit Posts', 'group' => 'blog'],
            'blog.posts.view' => ['label' => 'View Posts', 'group' => 'blog'],
        ]);
});

it('updates the label and group of an existing permission that changed', function (): void {
    $this->connection->addPermission('blog.posts.view', 'Old Label', 'blog');
    $this->connection->addPermission('blog.posts.edit', 'Edit Posts', 'old-group');
    $this->registry->register('blog.posts.view', 'View Posts', 'blog');
    $this->registry->register('blog.posts.edit', 'Edit Posts', 'blog');

    $result = $this->repository->syncFromRegistry($this->registry);

    expect($result->updated)->toEqualCanonicalizing(['blog.posts.view', 'blog.posts.edit'])
        ->and($result->created)->toBe([])
        ->and($this->connection->permissions())->toBe([
            'blog.posts.edit' => ['label' => 'Edit Posts', 'group' => 'blog'],
            'blog.posts.view' => ['label' => 'View Posts', 'group' => 'blog'],
        ]);
});

it('leaves an unchanged permission alone', function (): void {
    $this->connection->addPermission('blog.posts.view', 'View Posts', 'blog');
    $this->registry->register('blog.posts.view', 'View Posts', 'blog');

    $result = $this->repository->syncFromRegistry($this->registry);

    expect($result->updated)->toBe([])
        ->and($result->created)->toBe([])
        ->and($this->connection->statements('UPDATE'))->toBe([])
        ->and($this->connection->statements('INSERT'))->toBe([]);
});

it('reports unregistered permissions with the number of roles holding each', function (): void {
    $this->connection->addPermission('blog.posts.view', 'View Posts', 'blog');
    $this->connection->addPermission('legacy.export', 'Export', 'legacy');
    $this->connection->addPermission('legacy.import', 'Import', 'legacy');
    $this->connection->grant(1, 'legacy.export');
    $this->connection->grant(2, 'legacy.export');
    $this->connection->grant(1, 'blog.posts.view');
    $this->registry->register('blog.posts.view', 'View Posts', 'blog');

    $result = $this->repository->syncFromRegistry($this->registry);

    expect($result->unregistered)->toEqual([
        new UnregisteredPermission(id: 2, key: 'legacy.export', label: 'Export', group: 'legacy', roleCount: 2),
        new UnregisteredPermission(id: 3, key: 'legacy.import', label: 'Import', group: 'legacy', roleCount: 0),
    ])
        ->and($this->repository->findUnregistered($this->registry))->toEqual($result->unregistered);
});

it('reports wildcard keys as kept rather than unregistered', function (): void {
    $this->connection->addPermission('catalog.*', 'All Catalog', 'catalog');
    $this->connection->addPermission('*', 'Everything', 'all');
    $this->connection->grant(1, 'catalog.*');

    $result = $this->repository->syncFromRegistry($this->registry);

    expect($result->wildcardKeys)->toBe(['*', 'catalog.*'])
        ->and($result->unregistered)->toBe([]);
});

it('deletes nothing when syncing', function (): void {
    $this->connection->addPermission('legacy.export', 'Export', 'legacy');
    $this->connection->addPermission('catalog.*', 'All Catalog', 'catalog');
    $this->connection->grant(1, 'legacy.export');

    $this->repository->syncFromRegistry($this->registry);

    expect($this->connection->statements('DELETE'))->toBe([])
        ->and(array_keys($this->connection->permissions()))->toBe(['catalog.*', 'legacy.export'])
        ->and($this->connection->keysForRole(1))->toBe(['legacy.export']);
});

it('writes the sync in one transaction', function (): void {
    $this->registry->register('blog.posts.view', 'View Posts', 'blog');
    $this->registry->register('blog.posts.edit', 'Edit Posts', 'blog');
    $this->connection->failOn = 'INSERT';

    expect(fn () => $this->repository->syncFromRegistry($this->registry))->toThrow(RuntimeException::class);

    $this->connection->failOn = null;

    expect($this->connection->permissions())->toBe([])
        ->and($this->connection->log)->toContain('ROLLBACK');
});

it('counts each role once when role_permissions holds duplicate rows', function (): void {
    $this->connection->addPermission('legacy.export', 'Export', 'legacy');
    $this->connection->grant(1, 'legacy.export');
    $this->connection->grant(1, 'legacy.export');
    $this->connection->grant(2, 'legacy.export');

    $result = $this->repository->syncFromRegistry($this->registry);

    expect($result->unregistered[0]->roleCount)->toBe(2);
});

it('declares syncFromRegistry returning PermissionSyncResult on the interface', function (): void {
    $method = new ReflectionMethod(PermissionRepositoryInterface::class, 'syncFromRegistry');

    expect((string) $method->getReturnType())->toBe(PermissionSyncResult::class);
});

it('declares findUnregistered on the interface', function (): void {
    $method = new ReflectionMethod(PermissionRepositoryInterface::class, 'findUnregistered');

    expect((string) $method->getReturnType())->toBe('array')
        ->and($method->getParameters()[0]->getName())->toBe('registry');
});
