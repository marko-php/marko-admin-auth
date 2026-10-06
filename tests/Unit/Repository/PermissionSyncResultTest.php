<?php

declare(strict_types=1);

use Marko\AdminAuth\Repository\PermissionSyncResult;
use Marko\AdminAuth\Repository\UnregisteredPermission;

it('exposes the created, updated, unregistered and wildcard keys of a sync', function (): void {
    $unregistered = new UnregisteredPermission(
        id: 7,
        key: 'legacy.export',
        label: 'Export',
        group: 'legacy',
        roleCount: 2,
    );

    $result = new PermissionSyncResult(
        registeredCount: 4,
        created: ['blog.posts.create'],
        updated: ['blog.posts.edit'],
        unregistered: [$unregistered],
        wildcardKeys: ['catalog.*'],
    );

    expect($result->registeredCount)->toBe(4)
        ->and($result->created)->toBe(['blog.posts.create'])
        ->and($result->updated)->toBe(['blog.posts.edit'])
        ->and($result->unregistered)->toBe([$unregistered])
        ->and($result->wildcardKeys)->toBe(['catalog.*']);
});

it('counts created, updated and unregistered permissions', function (): void {
    $result = new PermissionSyncResult(
        registeredCount: 4,
        created: ['a.one', 'a.two'],
        updated: ['a.three'],
        unregistered: [
            new UnregisteredPermission(id: 7, key: 'b.one', label: 'One', group: 'b', roleCount: 0),
            new UnregisteredPermission(id: 7, key: 'b.two', label: 'Two', group: 'b', roleCount: 1),
            new UnregisteredPermission(id: 7, key: 'b.three', label: 'Three', group: 'b', roleCount: 0),
        ],
        wildcardKeys: [],
    );

    expect($result->createdCount())->toBe(2)
        ->and($result->updatedCount())->toBe(1)
        ->and($result->unregisteredCount())->toBe(3);
});

it('exposes the key, label, group and role count of an unregistered permission', function (): void {
    $permission = new UnregisteredPermission(
        id: 7,
        key: 'legacy.export',
        label: 'Export',
        group: 'legacy',
        roleCount: 2,
    );

    expect($permission->key)->toBe('legacy.export')
        ->and($permission->label)->toBe('Export')
        ->and($permission->group)->toBe('legacy')
        ->and($permission->roleCount)->toBe(2);
});
