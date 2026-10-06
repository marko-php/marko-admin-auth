<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Tests\Unit;

use Marko\Admin\Config\AdminConfigInterface;
use Marko\Admin\Discovery\AdminSectionCacheContributor;
use Marko\Admin\Discovery\DiscoveredAdminSections;
use Marko\AdminAuth\AdminGuardResolver;
use Marko\AdminAuth\Attributes\RequiresPermission;
use Marko\AdminAuth\Contracts\PermissionRegistryInterface;
use Marko\AdminAuth\Entity\AdminUser;
use Marko\AdminAuth\Entity\Role;
use Marko\AdminAuth\Middleware\AdminAuthMiddleware;
use Marko\AdminAuth\PermissionRegistry;
use Marko\AdminAuth\RegisteredPermission;
use Marko\AdminAuth\Tests\Fixtures\FixedAdminGuardResolver;
use Marko\Authentication\Contracts\GuardInterface;
use Marko\Core\Container\BindingRegistry;
use Marko\Core\Container\Container;
use Marko\Core\Container\ContainerInterface;
use Marko\Core\Container\PreferenceRegistry;
use Marko\Core\Discovery\CachedDiscovery;
use Marko\Core\Module\ModuleManifest;
use Marko\Core\Module\ModuleRepository;
use Marko\Core\Module\ModuleRepositoryInterface;
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
    $container->instance(
        AdminGuardResolver::class,
        new FixedAdminGuardResolver($guard ?? new FakeGuard(name: 'admin')),
    );
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

/**
 * Add what a real boot provides before admin-auth's boot callback runs: the module
 * repository, the discovery cache and marko/admin's shared section definitions.
 */
function bootAdminAuthModule(
    Container $container,
    CachedDiscovery $cachedDiscovery,
    ModuleManifest ...$modules,
): void {
    $container->instance(ContainerInterface::class, $container);
    $container->instance(CachedDiscovery::class, $cachedDiscovery);
    $container->instance(ModuleRepositoryInterface::class, new ModuleRepository($modules));
    $container->singleton(DiscoveredAdminSections::class);

    $container->call(adminAuthModule()['boot']);
}

/**
 * @return array<int, array{string, string, string}>
 */
function registeredPermissionRows(
    Container $container,
): array {
    return array_map(
        static fn (RegisteredPermission $permission): array => [$permission->key, $permission->label, $permission->group],
        $container->get(PermissionRegistryInterface::class)->all(),
    );
}

it('registers attribute-declared permissions at boot', function (): void {
    $path = sys_get_temp_dir() . '/marko-admin-auth-wiring-' . bin2hex(random_bytes(8));
    mkdir($path . '/src', 0755, true);
    file_put_contents($path . '/src/InventorySection.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace AdminAuthWiringBoot;

use Marko\Admin\Attributes\AdminPermission;
use Marko\Admin\Attributes\AdminSection;
use Marko\Admin\Contracts\AdminSectionInterface;

#[AdminSection(id: 'inventory', label: 'Inventory')]
#[AdminPermission(id: 'inventory.items.view', label: 'View Items')]
#[AdminPermission(id: 'inventory.items.edit', label: 'Edit Items')]
class InventorySection implements AdminSectionInterface
{
    public function getId(): string { return 'inventory'; }
    public function getLabel(): string { return 'Inventory'; }
    public function getIcon(): string { return ''; }
    public function getSortOrder(): int { return 0; }
    public function getMenuItems(): array { return []; }
}
PHP);
    $container = adminAuthModuleContainer();

    try {
        bootAdminAuthModule(
            $container,
            new CachedDiscovery(),
            new ModuleManifest(name: 'app/inventory', version: '1.0.0', path: $path),
        );
    } finally {
        unlink($path . '/src/InventorySection.php');
        rmdir($path . '/src');
        rmdir($path);
    }

    expect(registeredPermissionRows($container))->toBe([
        ['inventory.items.view', 'View Items', 'inventory'],
        ['inventory.items.edit', 'Edit Items', 'inventory'],
    ]);
});

it('registers permissions from a warm discovery cache without scanning', function (): void {
    $container = adminAuthModuleContainer();

    // The only module points at a path that does not exist: a scan would find nothing.
    bootAdminAuthModule(
        $container,
        new CachedDiscovery([
            AdminSectionCacheContributor::KEY => [
                [
                    'className' => 'App\\Admin\\ShippingSection',
                    'id' => 'shipping',
                    'label' => 'Shipping',
                    'icon' => '',
                    'sortOrder' => 0,
                    'permissions' => [['id' => 'shipping.rates.edit', 'label' => 'Edit Rates']],
                ],
            ],
        ]),
        new ModuleManifest(
            name: 'app/missing',
            version: '1.0.0',
            path: sys_get_temp_dir() . '/marko-admin-auth-wiring-missing',
        ),
    );

    expect(registeredPermissionRows($container))->toBe([['shipping.rates.edit', 'Edit Rates', 'shipping']]);
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
