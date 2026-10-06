<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Tests\Unit\Middleware;

use Marko\Admin\Config\AdminConfigInterface;
use Marko\AdminAuth\Attributes\RequiresPermission;
use Marko\AdminAuth\Contracts\PermissionRegistryInterface;
use Marko\AdminAuth\Entity\AdminUser;
use Marko\AdminAuth\Entity\Role;
use Marko\AdminAuth\Middleware\AdminAuthMiddleware;
use Marko\AdminAuth\PermissionRegistry;
use Marko\Authentication\AuthenticatableInterface;
use Marko\Authentication\Contracts\GuardInterface;
use Marko\Routing\Exceptions\HttpException;
use Marko\Routing\Http\Request;
use Marko\Routing\Http\Response;
use Marko\Testing\Fake\FakeGuard;
use RuntimeException;

// Test controller classes for attribute reflection
class TestControllerWithPermission
{
    #[RequiresPermission('posts.create')]
    public function create(): Response
    {
        return new Response(body: 'created', statusCode: 200);
    }
}

class TestControllerWithoutPermission
{
    public function index(): Response
    {
        return new Response(body: 'index', statusCode: 200);
    }
}

class TestControllerWithWildcardPermission
{
    #[RequiresPermission('posts.delete')]
    public function delete(): Response
    {
        return new Response(body: 'deleted', statusCode: 200);
    }
}

// Simple stub for AdminConfigInterface
readonly class StubAdminConfig implements AdminConfigInterface
{
    public function __construct(
        private string $routePrefix = '/admin',
        private string $name = 'Admin',
    ) {}

    public function getRoutePrefix(): string
    {
        return $this->routePrefix;
    }

    public function getName(): string
    {
        return $this->name;
    }
}

// Helper to create a standard middleware instance
function createMiddleware(
    ?GuardInterface $guard = null,
    ?AdminConfigInterface $adminConfig = null,
    ?PermissionRegistryInterface $permissionRegistry = null,
): AdminAuthMiddleware {
    return new AdminAuthMiddleware(
        guard: $guard ?? new FakeGuard(name: 'admin', attemptResult: false),
        adminConfig: $adminConfig ?? new StubAdminConfig(),
        permissionRegistry: $permissionRegistry ?? new PermissionRegistry(),
    );
}

function createAdminUser(
    ?array $roles = null,
    array $permissionKeys = [],
): AdminUser {
    $user = new AdminUser();
    $user->id = 1;
    $user->email = 'admin@example.com';
    $user->password = 'hashed';
    $user->name = 'Admin User';

    if ($roles !== null) {
        $user->setRoles(roles: $roles, permissionKeys: $permissionKeys);
    }

    return $user;
}

function createSuccessNext(): callable
{
    return fn (Request $r): Response => new Response(body: 'success', statusCode: 200);
}

function captureHttpException(
    AdminAuthMiddleware $middleware,
    Request $request,
): HttpException {
    try {
        $middleware->handle($request, createSuccessNext());
    } catch (HttpException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected AdminAuthMiddleware to throw an HttpException.');
}

it('throws a 401 HttpException for an unauthenticated request that wants JSON', function (): void {
    $guard = new FakeGuard(name: 'admin', attemptResult: false); // No user set
    $middleware = createMiddleware(guard: $guard);

    $request = (new Request(server: ['HTTP_ACCEPT' => 'application/json']))
        ->withRoute(TestControllerWithPermission::class, 'create');

    $exception = captureHttpException($middleware, $request);

    expect($exception->getStatusCode())->toBe(401)
        ->and($exception->getMessage())->toBe('Unauthorized.');
});

it('passes through when user is authenticated and no RequiresPermission attribute present', function (): void {
    $guard = new FakeGuard(name: 'admin', attemptResult: false);
    $user = createAdminUser();
    $guard->setUser($user);

    $middleware = createMiddleware(guard: $guard);

    $request = (new Request())->withRoute(TestControllerWithoutPermission::class, 'index');

    $response = $middleware->handle($request, createSuccessNext());

    expect($response->statusCode())->toBe(200)
        ->and($response->body())->toBe('success');
});

it('passes through when user has the required permission', function (): void {
    $guard = new FakeGuard(name: 'admin', attemptResult: false);
    $editorRole = new Role();
    $editorRole->id = 1;
    $editorRole->name = 'Editor';
    $editorRole->slug = 'editor';

    $user = createAdminUser(
        roles: [$editorRole],
        permissionKeys: ['posts.create', 'posts.edit'],
    );
    $guard->setUser($user);

    $middleware = createMiddleware(guard: $guard);

    $request = (new Request())->withRoute(TestControllerWithPermission::class, 'create');

    $response = $middleware->handle($request, createSuccessNext());

    expect($response->statusCode())->toBe(200)
        ->and($response->body())->toBe('success');
});

it('throws a 403 HttpException when the user lacks the required permission', function (): void {
    $guard = new FakeGuard(name: 'admin', attemptResult: false);
    $editorRole = new Role();
    $editorRole->id = 1;
    $editorRole->name = 'Editor';
    $editorRole->slug = 'editor';

    // User has posts.edit but NOT posts.create
    $user = createAdminUser(
        roles: [$editorRole],
        permissionKeys: ['posts.edit'],
    );
    $guard->setUser($user);

    $middleware = createMiddleware(guard: $guard);

    $request = (new Request())->withRoute(TestControllerWithPermission::class, 'create');

    $exception = captureHttpException($middleware, $request);

    expect($exception->getStatusCode())->toBe(403)
        ->and($exception->getMessage())->toBe('Forbidden.');
});

it('passes through for super admin users regardless of permission', function (): void {
    $guard = new FakeGuard(name: 'admin', attemptResult: false);
    $superAdminRole = new Role();
    $superAdminRole->id = 1;
    $superAdminRole->name = 'Super Admin';
    $superAdminRole->slug = 'super-admin';
    $superAdminRole->isSuperAdmin = '1';

    // Super admin has NO explicit permission keys, but should still pass
    $user = createAdminUser(
        roles: [$superAdminRole],
    );
    $guard->setUser($user);

    $middleware = createMiddleware(guard: $guard);

    $request = (new Request())->withRoute(TestControllerWithPermission::class, 'create');

    $response = $middleware->handle($request, createSuccessNext());

    expect($response->statusCode())->toBe(200)
        ->and($response->body())->toBe('success');
});

it('redirects an unauthenticated browser request to the admin login', function (): void {
    $guard = new FakeGuard(name: 'admin', attemptResult: false); // No user
    $adminConfig = new StubAdminConfig(routePrefix: '/admin');

    $middleware = createMiddleware(
        guard: $guard,
        adminConfig: $adminConfig,
    );

    // Web request: no Accept: application/json header
    $request = (new Request(server: [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/admin/posts/create',
    ]))->withRoute(TestControllerWithPermission::class, 'create');

    $response = $middleware->handle($request, createSuccessNext());

    expect($response->statusCode())->toBe(302)
        ->and($response->headers())->toHaveKey('Location')
        ->and($response->headers()['Location'])->toBe('/admin/login');
});

it('throws a 401 HttpException for an unauthenticated request asking for a +json type', function (): void {
    $guard = new FakeGuard(name: 'admin', attemptResult: false); // No user

    $middleware = createMiddleware(guard: $guard);

    $request = (new Request(server: [
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/admin/api/posts',
        'HTTP_ACCEPT' => 'application/vnd.api+json',
    ]))->withRoute(TestControllerWithPermission::class, 'create');

    $exception = captureHttpException($middleware, $request);

    expect($exception->getStatusCode())->toBe(401);
});

it('keeps the required permission key out of the 403 message and puts it in the context', function (): void {
    $guard = new FakeGuard(name: 'admin', attemptResult: false);
    $viewerRole = new Role();
    $viewerRole->id = 1;
    $viewerRole->name = 'Viewer';
    $viewerRole->slug = 'viewer';

    // User has posts.view but NOT posts.create
    $user = createAdminUser(
        roles: [$viewerRole],
        permissionKeys: ['posts.view'],
    );
    $guard->setUser($user);

    $middleware = createMiddleware(guard: $guard);

    // API request
    $request = (new Request(server: [
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/admin/api/posts',
        'HTTP_ACCEPT' => 'application/json',
    ]))->withRoute(TestControllerWithPermission::class, 'create');

    $exception = captureHttpException($middleware, $request);

    expect($exception->getStatusCode())->toBe(403)
        ->and($exception->getMessage())->not->toContain('posts.create')
        ->and($exception->getResponseData())->toBe(['message' => 'Forbidden.'])
        ->and($exception->getContext())->toContain('posts.create');
});

it('supports wildcard permission matching via user roles', function (): void {
    $guard = new FakeGuard(name: 'admin', attemptResult: false);
    $managerRole = new Role();
    $managerRole->id = 1;
    $managerRole->name = 'Posts Manager';
    $managerRole->slug = 'posts-manager';

    // User has wildcard 'posts.*' permission, controller requires 'posts.delete'
    $user = createAdminUser(
        roles: [$managerRole],
        permissionKeys: ['posts.*'],
    );
    $guard->setUser($user);

    $middleware = createMiddleware(guard: $guard);

    $request = (new Request())->withRoute(TestControllerWithWildcardPermission::class, 'delete');

    $response = $middleware->handle($request, createSuccessNext());

    expect($response->statusCode())->toBe(200)
        ->and($response->body())->toBe('success');
});

it('allows an authenticated admin through a route with no RequiresPermission attribute', function (): void {
    $guard = new FakeGuard(name: 'admin', attemptResult: false);
    $user = createAdminUser();
    $guard->setUser($user);

    $middleware = createMiddleware(guard: $guard);

    $request = (new Request())->withRoute(TestControllerWithoutPermission::class, 'index');

    $response = $middleware->handle($request, createSuccessNext());

    expect($response->statusCode())->toBe(200)
        ->and($response->body())->toBe('success');
});

it('denies a low-privilege admin with a 403 on a RequiresPermission route they lack', function (): void {
    $guard = new FakeGuard(name: 'admin', attemptResult: false);
    $viewerRole = new Role();
    $viewerRole->id = 1;
    $viewerRole->name = 'Viewer';
    $viewerRole->slug = 'viewer';

    $user = createAdminUser(roles: [$viewerRole], permissionKeys: ['posts.view']);
    $guard->setUser($user);

    $middleware = createMiddleware(guard: $guard);

    $request = (new Request())->withRoute(TestControllerWithPermission::class, 'create');

    expect(captureHttpException($middleware, $request)->getStatusCode())->toBe(403);
});

it('allows a properly-permissioned admin on a RequiresPermission route', function (): void {
    $guard = new FakeGuard(name: 'admin', attemptResult: false);
    $editorRole = new Role();
    $editorRole->id = 1;
    $editorRole->name = 'Editor';
    $editorRole->slug = 'editor';

    $user = createAdminUser(roles: [$editorRole], permissionKeys: ['posts.create', 'posts.edit']);
    $guard->setUser($user);

    $middleware = createMiddleware(guard: $guard);

    $request = (new Request())->withRoute(TestControllerWithPermission::class, 'create');

    $response = $middleware->handle($request, createSuccessNext());

    expect($response->statusCode())->toBe(200)
        ->and($response->body())->toBe('success');
});

it('reads the required permission from the route controller and action on the request', function (): void {
    $guard = new FakeGuard(name: 'admin', attemptResult: false);
    $viewerRole = new Role();
    $viewerRole->id = 1;
    $viewerRole->name = 'Viewer';
    $viewerRole->slug = 'viewer';

    // User has no posts.create permission
    $user = createAdminUser(roles: [$viewerRole], permissionKeys: ['posts.view']);
    $guard->setUser($user);

    $middleware = createMiddleware(guard: $guard);

    // Route context attached via withRoute — NOT via constructor params
    $request = (new Request())->withRoute(TestControllerWithPermission::class, 'create');

    // Permission must have been read from the request's route context
    expect(captureHttpException($middleware, $request)->getStatusCode())->toBe(403);
});

it('requires no permission when the request carries no route context (controller/action null)', function (): void {
    $guard = new FakeGuard(name: 'admin', attemptResult: false);
    $user = createAdminUser();
    $guard->setUser($user);

    $middleware = createMiddleware(guard: $guard);

    // No withRoute() — request has no route context
    $request = new Request();

    $response = $middleware->handle($request, createSuccessNext());

    expect($response->statusCode())->toBe(200)
        ->and($response->body())->toBe('success');
});

it('throws the 401 for a JSON request on a route without a permission requirement', function (): void {
    $guard = new FakeGuard(name: 'admin', attemptResult: false); // No user set

    $middleware = createMiddleware(guard: $guard);

    $request = (new Request(server: ['HTTP_ACCEPT' => 'application/json']))
        ->withRoute(TestControllerWithoutPermission::class, 'index');

    expect(captureHttpException($middleware, $request)->getStatusCode())->toBe(401);
});

it(
    'throws a 403 HttpException when the authenticated user is not an admin user on a gated route',
    function (): void {
        $guard = new FakeGuard(name: 'admin', attemptResult: false);

        // Authenticated as a non-admin user (not AdminUserInterface)
        $nonAdminUser = new class () implements AuthenticatableInterface
        {
            public function getAuthIdentifier(): int|string
            {
                return 99;
            }

            public function getAuthIdentifierName(): string
            {
                return 'id';
            }

            public function getAuthPassword(): string
            {
                return 'password';
            }

            public function getRememberToken(): ?string
            {
                return null;
            }

            public function setRememberToken(?string $token): void {}

            public function getRememberTokenName(): string
            {
                return 'remember_token';
            }
        };
        $guard->setUser($nonAdminUser);

        $middleware = createMiddleware(guard: $guard);

        $request = (new Request())->withRoute(TestControllerWithPermission::class, 'create');

        $exception = captureHttpException($middleware, $request);

        expect($exception->getStatusCode())->toBe(403)
            ->and($exception->getMessage())->toBe('Forbidden.')
            ->and($exception->getContext())->toContain('not an admin user');
    },
);
