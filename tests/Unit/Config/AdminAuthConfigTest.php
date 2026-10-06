<?php

declare(strict_types=1);

use Marko\AdminAuth\AdminUserProvider;
use Marko\AdminAuth\Config\AdminAuthConfig;
use Marko\AdminAuth\Config\AdminAuthConfigInterface;
use Marko\AdminAuth\Entity\AdminUser;
use Marko\AdminAuth\Entity\AdminUserInterface;
use Marko\AdminAuth\Entity\Role;
use Marko\AdminAuth\Entity\RoleInterface;
use Marko\AdminAuth\Events\AdminUserCreated;
use Marko\AdminAuth\Events\AdminUserDeleted;
use Marko\AdminAuth\Events\AdminUserUpdated;
use Marko\AdminAuth\Events\PermissionsSynced;
use Marko\AdminAuth\Events\RoleCreated;
use Marko\AdminAuth\Events\RoleDeleted;
use Marko\AdminAuth\Events\RoleUpdated;
use Marko\AdminAuth\Repository\AdminUserRepository;
use Marko\AdminAuth\Repository\AdminUserRepositoryInterface;
use Marko\AdminAuth\Repository\PermissionRepository;
use Marko\AdminAuth\Repository\PermissionRepositoryInterface;
use Marko\AdminAuth\Repository\RoleRepository;
use Marko\AdminAuth\Repository\RoleRepositoryInterface;
use Marko\Authentication\Contracts\UserProviderInterface;
use Marko\Core\Event\Event;
use Marko\Testing\Fake\FakeConfigRepository;

it('creates AdminAuthConfig with guard name and super admin role slug', function (): void {
    $config = new AdminAuthConfig(new FakeConfigRepository([
        'admin-auth.guard' => 'admin',
        'admin-auth.super_admin_role' => 'super-admin',
    ]));

    expect($config)->toBeInstanceOf(AdminAuthConfigInterface::class)
        ->and($config->getGuardName())->toBe('admin')
        ->and($config->getSuperAdminRoleSlug())->toBe('super-admin');
});

it('binds AdminUserRepositoryInterface to AdminUserRepository in module.php', function (): void {
    $modulePath = dirname(__DIR__, 3) . '/module.php';
    $module = require $modulePath;

    expect(file_exists($modulePath))->toBeTrue()
        ->and($module)->toBeArray()
        ->and($module)->toHaveKey('bindings')
        ->and($module['bindings'])->toHaveKey(AdminUserRepositoryInterface::class)
        ->and($module['bindings'][AdminUserRepositoryInterface::class])
            ->toBe(AdminUserRepository::class);
});

it('binds RoleRepositoryInterface to RoleRepository in module.php', function (): void {
    $modulePath = dirname(__DIR__, 3) . '/module.php';

    $module = require $modulePath;

    expect($module['bindings'])->toHaveKey(RoleRepositoryInterface::class)
        ->and($module['bindings'][RoleRepositoryInterface::class])
            ->toBe(RoleRepository::class);
});

it('binds PermissionRepositoryInterface to PermissionRepository in module.php', function (): void {
    $modulePath = dirname(__DIR__, 3) . '/module.php';

    $module = require $modulePath;

    expect($module['bindings'])->toHaveKey(PermissionRepositoryInterface::class)
        ->and($module['bindings'][PermissionRepositoryInterface::class])
            ->toBe(PermissionRepository::class);
});

it('does not bind the global UserProviderInterface, so the app keeps its own frontend provider', function (): void {
    $module = require dirname(__DIR__, 3) . '/module.php';

    expect($module['bindings'])->not->toHaveKey(UserProviderInterface::class);
});

it('ships an admin session guard with its own AdminUserProvider in config/authentication.php', function (): void {
    $config = require dirname(__DIR__, 3) . '/config/authentication.php';

    expect($config)->toBe([
        'guards' => [
            'admin' => ['driver' => 'session', 'provider' => 'admins'],
        ],
        'providers' => [
            'admins' => ['class' => AdminUserProvider::class],
        ],
    ]);
});

it('names the guard it ships as the admin-auth.guard default', function (): void {
    $adminAuth = require dirname(__DIR__, 3) . '/config/admin-auth.php';
    $authentication = require dirname(__DIR__, 3) . '/config/authentication.php';

    expect($authentication['guards'])->toHaveKey($adminAuth['guard']);
});

it('creates RoleCreated, RoleUpdated, RoleDeleted events', function (): void {
    $role = new Role();
    $role->id = 1;
    $role->name = 'Editor';
    $role->slug = 'editor';
    $timestamp = new DateTimeImmutable('2026-01-01 12:00:00 UTC');

    $created = new RoleCreated(role: $role, timestamp: $timestamp);
    $updated = new RoleUpdated(role: $role, timestamp: $timestamp);
    $deleted = new RoleDeleted(role: $role, timestamp: $timestamp);

    expect($created)->toBeInstanceOf(Event::class)
        ->and($created->getRole())->toBeInstanceOf(RoleInterface::class)
        ->and($created->getRole()->getName())->toBe('Editor')
        ->and($created->getTimestamp())->toBe($timestamp)
        ->and($updated)->toBeInstanceOf(Event::class)
        ->and($updated->getRole()->getSlug())->toBe('editor')
        ->and($deleted)->toBeInstanceOf(Event::class)
        ->and($deleted->getRole()->getId())->toBe(1);
});

it('creates AdminUserCreated, AdminUserUpdated, AdminUserDeleted events', function (): void {
    $user = new AdminUser();
    $user->id = 1;
    $user->email = 'admin@example.com';
    $user->password = 'hashed';
    $user->name = 'Admin';
    $timestamp = new DateTimeImmutable('2026-01-01 12:00:00 UTC');

    $created = new AdminUserCreated(user: $user, timestamp: $timestamp);
    $updated = new AdminUserUpdated(user: $user, timestamp: $timestamp);
    $deleted = new AdminUserDeleted(user: $user, timestamp: $timestamp);

    expect($created)->toBeInstanceOf(Event::class)
        ->and($created->getUser())->toBeInstanceOf(AdminUserInterface::class)
        ->and($created->getUser()->getAuthIdentifier())->toBe(1)
        ->and($created->getTimestamp())->toBe($timestamp)
        ->and($updated)->toBeInstanceOf(Event::class)
        ->and($updated->getUser()->getAuthIdentifier())->toBe(1)
        ->and($deleted)->toBeInstanceOf(Event::class)
        ->and($deleted->getUser())->toBeInstanceOf(AdminUserInterface::class)
        ->and($deleted->getTimestamp())->toBe($timestamp);
});

it('creates PermissionsSynced event dispatched after registry sync', function (): void {
    $timestamp = new DateTimeImmutable('2026-01-01 12:00:00 UTC');
    $event = new PermissionsSynced(
        createdCount: 5,
        totalCount: 12,
        timestamp: $timestamp,
    );

    expect($event)->toBeInstanceOf(Event::class)
        ->and($event->getCreatedCount())->toBe(5)
        ->and($event->getTotalCount())->toBe(12)
        ->and($event->getTimestamp())->toBe($timestamp);
});

it('has valid config/admin-auth.php with default values', function (): void {
    $configPath = dirname(__DIR__, 3) . '/config/admin-auth.php';
    $configData = require $configPath;

    expect(file_exists($configPath))->toBeTrue()
        ->and($configData)->toBeArray()
        ->and($configData)->toHaveKey('guard')
        ->and($configData)->toHaveKey('super_admin_role')
        ->and($configData['guard'])->toBe('admin')
        ->and($configData['super_admin_role'])->toBe('super-admin');
});

it('has module.php with all required bindings', function (): void {
    $modulePath = dirname(__DIR__, 3) . '/module.php';
    $module = require $modulePath;

    expect(file_exists($modulePath))->toBeTrue()
        ->and($module)->toBeArray()
        ->and($module)->toHaveKey('bindings')
        ->and($module['bindings'])->toHaveKey(AdminUserRepositoryInterface::class)
        ->and($module['bindings'])->toHaveKey(RoleRepositoryInterface::class)
        ->and($module['bindings'])->toHaveKey(PermissionRepositoryInterface::class)
        ->and($module['bindings'])->not->toHaveKey(UserProviderInterface::class)
        ->and($module['bindings'])->toHaveKey(AdminAuthConfigInterface::class)
        ->and($module['bindings'][AdminAuthConfigInterface::class])
            ->toBe(AdminAuthConfig::class);
});
