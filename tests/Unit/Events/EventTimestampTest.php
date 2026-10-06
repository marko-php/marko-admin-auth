<?php

declare(strict_types=1);

use Marko\AdminAuth\Events\AdminUserCreated;
use Marko\AdminAuth\Events\AdminUserDeleted;
use Marko\AdminAuth\Events\AdminUserUpdated;
use Marko\AdminAuth\Events\PermissionsSynced;
use Marko\AdminAuth\Events\RoleCreated;
use Marko\AdminAuth\Events\RoleDeleted;
use Marko\AdminAuth\Events\RoleUpdated;

it('requires a timestamp on every admin-auth event', function (string $eventClass): void {
    $parameters = new ReflectionMethod($eventClass, '__construct')->getParameters();
    $timestamp = array_values(array_filter(
        $parameters,
        fn (ReflectionParameter $parameter): bool => $parameter->getName() === 'timestamp',
    ));

    expect($timestamp)->toHaveCount(1)
        ->and($timestamp[0]->isOptional())->toBeFalse();
})->with([
    AdminUserCreated::class,
    AdminUserDeleted::class,
    AdminUserUpdated::class,
    PermissionsSynced::class,
    RoleCreated::class,
    RoleDeleted::class,
    RoleUpdated::class,
]);
