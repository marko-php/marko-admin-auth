<?php

declare(strict_types=1);

use Marko\AdminAuth\PermissionRegistry;
use Marko\AdminAuth\Repository\PermissionRepository;
use Marko\AdminAuth\Repository\UnregisteredPermission;
use Marko\AdminAuth\Tests\Fixtures\SqlitePermissionConnection;
use Marko\Database\Entity\EntityHydrator;
use Marko\Database\Entity\EntityMetadataFactory;

beforeEach(function (): void {
    $this->connection = new SqlitePermissionConnection();
    $metadataFactory = new EntityMetadataFactory();
    $this->repository = new PermissionRepository(
        $this->connection,
        $metadataFactory,
        new EntityHydrator($metadataFactory),
    );
    $this->registry = new PermissionRegistry();
    $this->registry->register('blog.posts.view', 'View Posts', 'blog');

    $this->connection->addPermission('blog.posts.view', 'View Posts', 'blog');
    $this->connection->addPermission('legacy.export', 'Export', 'legacy');
    $this->connection->addPermission('legacy.import', 'Import', 'legacy');
    $this->connection->addPermission('catalog.*', 'All Catalog', 'catalog');
    $this->connection->addPermission('*', 'Everything', 'all');
    $this->connection->grant(1, 'blog.posts.view');
    $this->connection->grant(1, 'legacy.export');
    $this->connection->grant(1, 'catalog.*');
    $this->connection->grant(2, 'legacy.export');
    $this->connection->grant(2, '*');
});

it('deletes unregistered permissions and their role assignments', function (): void {
    $this->repository->pruneUnregistered($this->registry);

    expect(array_keys($this->connection->permissions()))->not->toContain('legacy.export')
        ->and(array_keys($this->connection->permissions()))->not->toContain('legacy.import')
        ->and($this->connection->keysForRole(1))->not->toContain('legacy.export')
        ->and($this->connection->keysForRole(2))->not->toContain('legacy.export')
        ->and($this->connection->rolePermissionCount())->toBe(3);
});

it('never deletes a key containing a wildcard', function (): void {
    $this->repository->pruneUnregistered($this->registry);

    expect(array_keys($this->connection->permissions()))->toContain('catalog.*', '*')
        ->and($this->connection->keysForRole(1))->toContain('catalog.*')
        ->and($this->connection->keysForRole(2))->toBe(['*']);
});

it('keeps registered permissions and their role assignments', function (): void {
    $this->repository->pruneUnregistered($this->registry);

    expect($this->connection->permissions()['blog.posts.view'])->toBe(['label' => 'View Posts', 'group' => 'blog'])
        ->and($this->connection->keysForRole(1))->toBe(['blog.posts.view', 'catalog.*']);
});

it('returns the permissions it removed with their role counts', function (): void {
    $removed = $this->repository->pruneUnregistered($this->registry);

    expect($removed)->toEqual([
        new UnregisteredPermission(id: 2, key: 'legacy.export', label: 'Export', group: 'legacy', roleCount: 2),
        new UnregisteredPermission(id: 3, key: 'legacy.import', label: 'Import', group: 'legacy', roleCount: 0),
    ]);
});

it('runs the deletes in one transaction and rolls back on failure', function (): void {
    $this->connection->failOn = 'DELETE FROM "permissions"';

    expect(fn () => $this->repository->pruneUnregistered($this->registry))->toThrow(RuntimeException::class);

    $this->connection->failOn = null;

    expect(array_keys($this->connection->permissions()))->toContain('legacy.export', 'legacy.import')
        ->and($this->connection->keysForRole(2))->toBe(['*', 'legacy.export'])
        ->and($this->connection->rolePermissionCount())->toBe(5)
        ->and($this->connection->log)->toContain('ROLLBACK');
});

it('issues no delete when nothing is unregistered', function (): void {
    $this->registry->register('legacy.export', 'Export', 'legacy');
    $this->registry->register('legacy.import', 'Import', 'legacy');

    $removed = $this->repository->pruneUnregistered($this->registry);

    expect($removed)->toBe([])
        ->and($this->connection->statements('DELETE'))->toBe([]);
});
