<?php

declare(strict_types=1);

use Marko\Admin\Discovery\DiscoveredAdminSections;
use Marko\AdminAuth\Config\AdminAuthConfig;
use Marko\AdminAuth\Config\AdminAuthConfigInterface;
use Marko\AdminAuth\Contracts\PermissionRegistryInterface;
use Marko\AdminAuth\Discovery\PermissionDiscovery;
use Marko\AdminAuth\PermissionRegistry;
use Marko\AdminAuth\Repository\AdminUserRepository;
use Marko\AdminAuth\Repository\AdminUserRepositoryInterface;
use Marko\AdminAuth\Repository\PermissionRepository;
use Marko\AdminAuth\Repository\PermissionRepositoryInterface;
use Marko\AdminAuth\Repository\RememberTokenRepository;
use Marko\AdminAuth\Repository\RoleRepository;
use Marko\AdminAuth\Repository\RoleRepositoryInterface;
use Marko\Authentication\Contracts\RememberTokenStorageInterface;

return [
    // No global UserProviderInterface binding: the admin guard gets AdminUserProvider
    // through authentication.providers.admins (config/authentication.php), so the
    // app's frontend provider and the admin provider never replace each other.
    'bindings' => [
        AdminAuthConfigInterface::class => AdminAuthConfig::class,
        AdminUserRepositoryInterface::class => AdminUserRepository::class,
        RoleRepositoryInterface::class => RoleRepository::class,
        PermissionRepositoryInterface::class => PermissionRepository::class,
        // Per-device remember-me tokens (remember_tokens table) for every session guard, admin and frontend.
        RememberTokenStorageInterface::class => RememberTokenRepository::class,
    ],
    'singletons' => [
        // Shared: permissions registered through one injected registry must be visible to every consumer.
        PermissionRegistryInterface::class => PermissionRegistry::class,
    ],
    // Registers every #[AdminPermission] on the #[AdminSection] classes marko/admin discovered
    // (from the discovery cache, or one scan shared with marko/admin). In memory only: run
    // `marko admin-auth:permissions:sync` to write them to the permissions table.
    'boot' => function (
        DiscoveredAdminSections $discoveredAdminSections,
        PermissionDiscovery $permissionDiscovery,
    ): void {
        $permissionDiscovery->registerFromDefinitions($discoveredAdminSections->all());
    },
];
