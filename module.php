<?php

declare(strict_types=1);

use Marko\Admin\Discovery\DiscoveredAdminSections;
use Marko\AdminAuth\AdminUserProvider;
use Marko\AdminAuth\Config\AdminAuthConfig;
use Marko\AdminAuth\Config\AdminAuthConfigInterface;
use Marko\AdminAuth\Contracts\PermissionRegistryInterface;
use Marko\AdminAuth\Discovery\PermissionDiscovery;
use Marko\AdminAuth\PermissionRegistry;
use Marko\AdminAuth\Repository\AdminUserRepository;
use Marko\AdminAuth\Repository\AdminUserRepositoryInterface;
use Marko\AdminAuth\Repository\PermissionRepository;
use Marko\AdminAuth\Repository\PermissionRepositoryInterface;
use Marko\AdminAuth\Repository\RoleRepository;
use Marko\AdminAuth\Repository\RoleRepositoryInterface;
use Marko\Authentication\Contracts\PasswordHasherInterface;
use Marko\Authentication\Contracts\UserProviderInterface;
use Marko\Core\Container\ContainerInterface;

return [
    'bindings' => [
        AdminAuthConfigInterface::class => AdminAuthConfig::class,
        AdminUserRepositoryInterface::class => AdminUserRepository::class,
        RoleRepositoryInterface::class => RoleRepository::class,
        PermissionRepositoryInterface::class => PermissionRepository::class,
        UserProviderInterface::class => function (ContainerInterface $container): UserProviderInterface {
            return new AdminUserProvider(
                userRepository: $container->get(AdminUserRepositoryInterface::class),
                roleRepository: $container->get(RoleRepositoryInterface::class),
                passwordHasher: $container->get(PasswordHasherInterface::class),
            );
        },
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
