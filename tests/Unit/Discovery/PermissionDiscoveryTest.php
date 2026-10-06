<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Tests\Unit\Discovery;

use Marko\Admin\Attributes\AdminPermission;
use Marko\Admin\Attributes\AdminSection;
use Marko\Admin\Contracts\AdminSectionInterface;
use Marko\Admin\Contracts\MenuItemInterface;
use Marko\Admin\Discovery\AdminPermissionDefinition;
use Marko\Admin\Discovery\AdminSectionDefinition;
use Marko\Admin\Discovery\AdminSectionDiscovery;
use Marko\Admin\Exceptions\AdminException;
use Marko\AdminAuth\Discovery\PermissionDiscovery;
use Marko\AdminAuth\Exceptions\AdminAuthException;
use Marko\AdminAuth\PermissionRegistry;
use Marko\AdminAuth\RegisteredPermission;
use ReflectionException;

it('discovers permissions from AdminPermission attributes on AdminSection classes', function (): void {
    $registry = new PermissionRegistry();
    $sectionDiscovery = new AdminSectionDiscovery();
    $discovery = new PermissionDiscovery(
        registry: $registry,
        sectionDiscovery: $sectionDiscovery,
    );

    $discovery->discoverFromClass(DiscoverySectionWithPermissions::class);

    $permissions = $registry->all();
    expect($permissions)->toHaveCount(2)
        ->and($permissions[0]->key)->toBe('blog.posts.create')
        ->and($permissions[0]->label)->toBe('Create Posts')
        ->and($permissions[1]->key)->toBe('blog.posts.edit')
        ->and($permissions[1]->label)->toBe('Edit Posts');
});

it('derives group from first segment of permission key', function (): void {
    $registry = new PermissionRegistry();
    $sectionDiscovery = new AdminSectionDiscovery();
    $discovery = new PermissionDiscovery(
        registry: $registry,
        sectionDiscovery: $sectionDiscovery,
    );

    $discovery->discoverFromClass(DiscoverySectionWithPermissions::class);

    $permissions = $registry->all();
    expect($permissions[0]->group)->toBe('blog')
        ->and($permissions[1]->group)->toBe('blog');
});

it('throws AdminException when the class does not implement AdminSectionInterface', function (): void {
    $discovery = new PermissionDiscovery(
        registry: new PermissionRegistry(),
        sectionDiscovery: new AdminSectionDiscovery(),
    );

    expect(fn () => $discovery->discoverFromClass(DiscoveryMarkedWithoutInterface::class))
        ->toThrow(AdminException::class, 'does not implement AdminSectionInterface');
});

it('surfaces the missing AdminSection attribute exception unchanged', function (): void {
    $discovery = new PermissionDiscovery(
        registry: new PermissionRegistry(),
        sectionDiscovery: new AdminSectionDiscovery(),
    );

    try {
        $discovery->discoverFromClass(DiscoveryNotASection::class);
        $this->fail('Expected AdminException was not thrown');
    } catch (AdminException $e) {
        $expected = AdminException::missingSectionAttribute(DiscoveryNotASection::class);

        expect($e::class)->toBe(AdminException::class)
            ->and($e->getMessage())->toBe($expected->getMessage())
            ->and($e->getContext())->toBe($expected->getContext())
            ->and($e->getSuggestion())->toBe($expected->getSuggestion());
    }
});

it('registers no permissions when the class has no AdminSection attribute', function (): void {
    $registry = new PermissionRegistry();
    $discovery = new PermissionDiscovery(
        registry: $registry,
        sectionDiscovery: new AdminSectionDiscovery(),
    );

    try {
        $discovery->discoverFromClass(DiscoveryNotASection::class);
    } catch (AdminException) {
    }

    expect($registry->all())->toBeEmpty();
});

it('throws ReflectionException when the class does not exist', function (): void {
    $discovery = new PermissionDiscovery(
        registry: new PermissionRegistry(),
        sectionDiscovery: new AdminSectionDiscovery(),
    );

    /** @var class-string $missingClass */
    $missingClass = 'Marko\AdminAuth\Tests\Unit\Discovery\DiscoveryMissingSection';

    expect(fn () => $discovery->discoverFromClass($missingClass))
        ->toThrow(ReflectionException::class);
});

it('registers nothing when discovery fails', function (): void {
    $registry = new PermissionRegistry();
    $discovery = new PermissionDiscovery(
        registry: $registry,
        sectionDiscovery: new AdminSectionDiscovery(),
    );

    try {
        $discovery->discoverFromClass(DiscoveryMarkedWithoutInterface::class);
    } catch (AdminException) {
    }

    expect($registry->all())->toBeEmpty();
});

it('registers the permissions of every section definition grouped by the first key segment', function (): void {
    $registry = new PermissionRegistry();
    $discovery = new PermissionDiscovery(
        registry: $registry,
        sectionDiscovery: new AdminSectionDiscovery(),
    );

    $discovery->registerFromDefinitions([
        new AdminSectionDefinition(
            className: 'App\\Admin\\CatalogSection',
            id: 'catalog',
            label: 'Catalog',
            icon: '',
            sortOrder: 0,
            permissions: [new AdminPermissionDefinition(id: 'catalog.products.view', label: 'View Products')],
        ),
        new AdminSectionDefinition(
            className: 'App\\Admin\\SalesSection',
            id: 'sales',
            label: 'Sales',
            icon: '',
            sortOrder: 0,
            permissions: [new AdminPermissionDefinition(id: 'sales.orders.view', label: 'View Orders')],
        ),
    ]);

    expect(array_map(
        static fn (RegisteredPermission $permission): array => [$permission->key, $permission->label, $permission->group],
        $registry->all(),
    ))->toBe([
        ['catalog.products.view', 'View Products', 'catalog'],
        ['sales.orders.view', 'View Orders', 'sales'],
    ]);
});

it('throws duplicatePermission naming both classes when two sections declare the same key', function (): void {
    $discovery = new PermissionDiscovery(
        registry: new PermissionRegistry(),
        sectionDiscovery: new AdminSectionDiscovery(),
    );
    $permissions = [new AdminPermissionDefinition(id: 'shared.view', label: 'View')];

    expect(fn () => $discovery->registerFromDefinitions([
        new AdminSectionDefinition(
            className: 'App\\Admin\\FirstSection',
            id: 'first',
            label: 'First',
            icon: '',
            sortOrder: 0,
            permissions: $permissions,
        ),
        new AdminSectionDefinition(
            className: 'App\\Admin\\SecondSection',
            id: 'second',
            label: 'Second',
            icon: '',
            sortOrder: 0,
            permissions: $permissions,
        ),
    ]))->toThrow(
        AdminAuthException::class,
        "Permission with key 'shared.view' is declared by both 'App\\Admin\\FirstSection' and 'App\\Admin\\SecondSection'",
    );
});

it(
    'names the section class and suggests removing the manual registration when a key was already registered by hand',
    function (): void {
        $registry = new PermissionRegistry();
        $registry->register(key: 'blog.posts.create', label: 'Create Posts', group: 'blog');
        $discovery = new PermissionDiscovery(
            registry: $registry,
            sectionDiscovery: new AdminSectionDiscovery(),
        );

        try {
            $discovery->discoverFromClass(DiscoverySectionWithPermissions::class);
            $this->fail('Expected AdminAuthException was not thrown');
        } catch (AdminAuthException $e) {
            expect($e->getMessage())->toBe(
                "Permission with key 'blog.posts.create' declared by #[AdminPermission] on '"
                . DiscoverySectionWithPermissions::class . "' is already registered",
            )
                ->and($e->getSuggestion())->toContain('Remove the manual PermissionRegistryInterface::register() call')
                ->and($e->getPrevious())->toBeInstanceOf(AdminAuthException::class);
        }
    },
);

// Test fixture classes
#[AdminPermission(id: 'reports.view', label: 'View Reports')]
class DiscoveryNotASection {}

#[AdminSection(id: 'reports', label: 'Reports')]
#[AdminPermission(id: 'reports.export', label: 'Export Reports')]
class DiscoveryMarkedWithoutInterface {}

#[AdminSection(id: 'blog', label: 'Blog', icon: 'pencil', sortOrder: 10)]
#[AdminPermission(id: 'blog.posts.create', label: 'Create Posts')]
#[AdminPermission(id: 'blog.posts.edit', label: 'Edit Posts')]
class DiscoverySectionWithPermissions implements AdminSectionInterface
{
    public function getId(): string
    {
        return 'blog';
    }

    public function getLabel(): string
    {
        return 'Blog';
    }

    public function getIcon(): string
    {
        return 'pencil';
    }

    public function getSortOrder(): int
    {
        return 10;
    }

    /** @return array<MenuItemInterface> */
    public function getMenuItems(): array
    {
        return [];
    }
}
