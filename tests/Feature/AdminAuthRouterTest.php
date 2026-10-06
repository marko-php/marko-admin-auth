<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Tests\Feature;

use Marko\Admin\Config\AdminConfigInterface;
use Marko\AdminAuth\Attributes\RequiresPermission;
use Marko\AdminAuth\Contracts\PermissionRegistryInterface;
use Marko\AdminAuth\Entity\AdminUser;
use Marko\AdminAuth\Entity\Role;
use Marko\AdminAuth\Middleware\AdminAuthMiddleware;
use Marko\AdminAuth\PermissionRegistry;
use Marko\Authentication\Contracts\GuardInterface;
use Marko\Core\Container\Container;
use Marko\Routing\Http\Request;
use Marko\Routing\Http\Response;
use Marko\Routing\RouteCollection;
use Marko\Routing\RouteDefinition;
use Marko\Routing\RouteMatcher;
use Marko\Routing\Router;
use Marko\Testing\Fake\FakeGuard;

class RouterAdminPostController
{
    /** @noinspection PhpUnused - Invoked via the router */
    #[RequiresPermission('posts.create')]
    public function create(): Response
    {
        return new Response(body: 'created');
    }
}

readonly class RouterAdminConfig implements AdminConfigInterface
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

/**
 * Build a real Router with AdminAuthMiddleware as route middleware, as
 * #[Middleware(AdminAuthMiddleware::class)] attaches it in production.
 *
 * @param list<string>|null $permissionKeys null for a guest, otherwise the admin's permission keys
 */
function createAdminRouter(
    ?array $permissionKeys,
): Router {
    $guard = new FakeGuard(name: 'admin', attemptResult: false);

    if ($permissionKeys !== null) {
        $role = new Role();
        $role->id = 1;
        $role->name = 'Editor';
        $role->slug = 'editor';

        $user = new AdminUser();
        $user->id = 1;
        $user->email = 'admin@example.com';
        $user->password = 'hashed';
        $user->name = 'Admin User';
        $user->setRoles(roles: [$role], permissionKeys: $permissionKeys);

        $guard->setUser($user);
    }

    $container = new Container();
    $container->instance(GuardInterface::class, $guard);
    $container->instance(AdminConfigInterface::class, new RouterAdminConfig());
    $container->instance(PermissionRegistryInterface::class, new PermissionRegistry());

    $routes = new RouteCollection();
    $routes->add(new RouteDefinition(
        method: 'GET',
        path: '/admin/posts/create',
        controller: RouterAdminPostController::class,
        action: 'create',
        middleware: [AdminAuthMiddleware::class],
    ));

    return new Router(
        matcher: new RouteMatcher($routes),
        container: $container,
    );
}

function createAdminRouterRequest(
    string $accept,
): Request {
    return new Request(server: [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/admin/posts/create',
        'HTTP_ACCEPT' => $accept,
    ]);
}

it(
    'renders a JSON 401 through the exception renderer for an unauthenticated request that wants JSON',
    function (): void {
        $response = createAdminRouter(permissionKeys: null)->handle(createAdminRouterRequest('application/json'));

        expect($response->statusCode())->toBe(401)
            ->and($response->headers()['Content-Type'])->toContain('application/json')
            ->and(json_decode($response->body(), true))->toBe(['message' => 'Unauthorized.']);
    },
);

it('redirects an unauthenticated HTML request to the admin login through the router', function (): void {
    $response = createAdminRouter(permissionKeys: null)->handle(createAdminRouterRequest('text/html'));

    expect($response->statusCode())->toBe(302)
        ->and($response->headers()['Location'])->toBe('/admin/login');
});

it('renders a JSON 403 through the exception renderer when the admin lacks the permission', function (): void {
    $response = createAdminRouter(permissionKeys: ['posts.view'])
        ->handle(createAdminRouterRequest('application/json'));

    expect($response->statusCode())->toBe(403)
        ->and($response->headers()['Content-Type'])->toContain('application/json')
        ->and(json_decode($response->body(), true))->toBe(['message' => 'Forbidden.']);
});

it('renders an HTML 403 page through the exception renderer when the admin lacks the permission', function (): void {
    $response = createAdminRouter(permissionKeys: ['posts.view'])->handle(createAdminRouterRequest('text/html'));

    expect($response->statusCode())->toBe(403)
        ->and($response->headers()['Content-Type'])->toContain('text/html')
        ->and($response->body())->toContain('403 Forbidden')
        ->toContain('Forbidden.')
        ->not->toContain('created');
});

it('never includes the required permission key in the 403 body', function (): void {
    $router = createAdminRouter(permissionKeys: ['posts.view']);

    $json = $router->handle(createAdminRouterRequest('application/json'));
    $html = $router->handle(createAdminRouterRequest('text/html'));

    expect($json->body())->not->toContain('posts.create')
        ->and($html->body())->not->toContain('posts.create');
});

it('lets a permitted admin reach the controller through the router', function (): void {
    $response = createAdminRouter(permissionKeys: ['posts.create'])->handle(createAdminRouterRequest('text/html'));

    expect($response->statusCode())->toBe(200)
        ->and($response->body())->toBe('created');
});
