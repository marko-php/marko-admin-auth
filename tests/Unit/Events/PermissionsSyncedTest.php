<?php

declare(strict_types=1);

use Marko\AdminAuth\Events\PermissionsSynced;

it('exposes the updated, unregistered and pruned counts of a sync', function (): void {
    $event = new PermissionsSynced(
        createdCount: 2,
        totalCount: 5,
        timestamp: new DateTimeImmutable('2026-10-06 12:00:00'),
        updatedCount: 1,
        unregisteredCount: 3,
        prunedCount: 3,
    );

    expect($event->getUpdatedCount())->toBe(1)
        ->and($event->getUnregisteredCount())->toBe(3)
        ->and($event->getPrunedCount())->toBe(3);
});

it('defaults the updated, unregistered and pruned counts to zero', function (): void {
    $event = new PermissionsSynced(
        createdCount: 2,
        totalCount: 5,
        timestamp: new DateTimeImmutable('2026-10-06 12:00:00'),
    );

    expect($event->getUpdatedCount())->toBe(0)
        ->and($event->getUnregisteredCount())->toBe(0)
        ->and($event->getPrunedCount())->toBe(0);
});

it('keeps the created and total counts and the timestamp', function (): void {
    $timestamp = new DateTimeImmutable('2026-10-06 12:00:00');

    $event = new PermissionsSynced(
        createdCount: 2,
        totalCount: 5,
        timestamp: $timestamp,
        updatedCount: 1,
    );

    expect($event->getCreatedCount())->toBe(2)
        ->and($event->getTotalCount())->toBe(5)
        ->and($event->getTimestamp())->toBe($timestamp);
});
