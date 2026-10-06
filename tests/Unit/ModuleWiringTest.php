<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Tests\Unit;

use Marko\Admin\Config\AdminConfigInterface;
use Marko\AdminAuth\Attributes\RequiresPermission;
use Marko\AdminAuth\Contracts\PermissionRegistryInterface;
use Marko\AdminAuth\Entity\AdminUser;
use Marko\AdminAuth\Entity\Role;
use Marko\AdminAuth\Middleware\AdminAuthMiddleware;
use Marko\AdminAuth\PermissionRegistry;
use Marko\Authentication\Contracts\GuardInterface;
use Marko\Core\Container\BindingRegistry;
use Marko\Core\Container\Container;
use Marko\Core\Container\PreferenceRegistry;
use Marko\Core\Module\ModuleManifest;
use Marko\Routing\Http\Request;
use Marko\Routing\Http\Response;
use Marko\Testing\Fake\FakeGuard;

readonly class WiringAdminConfig implements AdminConfigInterface
{
    public function getRoutePrefix(): string
    {
        return '/admin';
    }

    public function getName(): string
    {
        return 'Admin';
    }
}

readonly class WiringCatalogPermissions
{
    public function __construct(
        public PermissionRegistryInterface $permissionRegistry,
    ) {}
}

readonly class WiringPermissionReader
{
    public function __construct(
        public PermissionRegistryInterface $permissionRegistry,
    ) {}
}

class WiringAppPermissionRegistry extends PermissionRegistry {}

class WiringProductController
{
    /** @noinspection PhpUnused - Invoked via the middleware */
    #[RequiresPermission('catalog.products.edit')]
    public function edit(): Response
    {
        return new Response(body: 'edited');
    }
}

/**
 * @return array<string, mixed>
 */
function adminAuthModule(): array
{
    return require dirname(__DIR__, 2) . '/module.php';
}

/**
 * Build a container wired only with admin-auth's module.php, plus the guard and
 * admin config that other packages provide in a real application.
 */
function adminAuthModuleContainer(
    ?GuardInterface $guard = null,
    ?PreferenceRegistry $preferenceRegistry = null,
): Container {
    $module = adminAuthModule();

    $container = new Container($preferenceRegistry);
    $container->instance(GuardInterface::class, $guard ?? new FakeGuard(name: 'admin'));
    $container->instance(AdminConfigInterface::class, new WiringAdminConfig());

    new BindingRegistry($container)->registerModule(new ModuleManifest(
        name: 'marko/admin-auth',
        version: '1.0.0',
        bindings: $module['bindings'],
        singletons: $module['singletons'] ?? [],
    ));

    return $container;
}

it('binds PermissionRegistryInterface to PermissionRegistry as a singleton in module.php', function (): void {
    $module = adminAuthModule();

    expect($module['singletons'][PermissionRegistryInterface::class] ?? null)->toBe(PermissionRegistry::class)
        ->and($module['bindings'])->not->toHaveKey(PermissionRegistryInterface::class);
});

it('resolves the same PermissionRegistry instance each time from the module bindings', function (): void {
    $container = adminAuthModuleContainer();

    $first = $container->get(PermissionRegistryInterface::class);

    expect($first)->toBeInstanceOf(PermissionRegistry::class)
        ->and($container->get(PermissionRegistryInterface::class))->toBe($first);
});

it('builds AdminAuthMiddleware from the module bindings without an app-level registry binding', function (): void {
    expect(adminAuthModuleContainer()->get(AdminAuthMiddleware::class))
        ->toBeInstanceOf(AdminAuthMiddleware::class);
});

it('shares registered permissions between separately injected consumers', function (): void {
    $container = adminAuthModuleContainer();

    $container->get(WiringCatalogPermissions::class)->permissionRegistry->register(
        key: 'catalog.products.edit',
        label: 'Edit Products',
        group: 'Catalog',
    );

    $keys = array_map(
        static fn ($permission): string => $permission->key,
        $container->get(WiringPermissionReader::class)->permissionRegistry->all(),
    );

    expect($keys)->toBe(['catalog.products.edit']);
});

it('lets a registered permission pass the middleware wildcard check through the shared registry', function (): void {
    $role = new Role();
    $role->id = 1;
    $role->name = 'Catalog Manager';
    $role->slug = 'catalog-manager';

    $user = new AdminUser();
    $user->id = 1;
    $user->email = 'admin@example.com';
    $user->password = 'hashed';
    $user->name = 'Admin User';
    $user->setRoles(roles: [$role], permissionKeys: ['catalog.*']);

    $guard = new FakeGuard(name: 'admin');
    $guard->setUser($user);

    $container = adminAuthModuleContainer($guard);
    $container->get(WiringCatalogPermissions::class)->permissionRegistry->register(
        key: 'catalog.products.edit',
        label: 'Edit Products',
        group: 'Catalog',
    );

    $response = $container->get(AdminAuthMiddleware::class)->handle(
        new Request()->withRoute(WiringProductController::class, 'edit'),
        fn (): Response => new Response(body: 'edited'),
    );

    expect($response->body())->toBe('edited');
});

it('shares a Preference that replaces PermissionRegistryInterface', function (): void {
    $preferenceRegistry = new PreferenceRegistry();
    $preferenceRegistry->register(
        original: PermissionRegistryInterface::class,
        replacement: WiringAppPermissionRegistry::class,
    );

    $container = adminAuthModuleContainer(preferenceRegistry: $preferenceRegistry);
    $registry = $container->get(PermissionRegistryInterface::class);

    expect($registry)->toBeInstanceOf(WiringAppPermissionRegistry::class)
        ->and($container->get(PermissionRegistryInterface::class))->toBe($registry);
});
