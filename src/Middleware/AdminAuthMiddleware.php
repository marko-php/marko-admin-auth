<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Middleware;

use Marko\Admin\Config\AdminConfigInterface;
use Marko\AdminAuth\Attributes\RequiresPermission;
use Marko\AdminAuth\Contracts\PermissionRegistryInterface;
use Marko\AdminAuth\Entity\AdminUserInterface;
use Marko\Authentication\Contracts\GuardInterface;
use Marko\Routing\Exceptions\HttpException;
use Marko\Routing\Http\Request;
use Marko\Routing\Http\Response;
use Marko\Routing\Middleware\MiddlewareInterface;
use ReflectionException;
use ReflectionMethod;

/**
 * Gates admin routes on an authenticated admin user and, when the matched
 * action carries #[RequiresPermission], on that permission.
 *
 * An unauthenticated browser request is redirected to `{prefix}/login`. An
 * unauthenticated request that wants JSON (Request::wantsJson()) gets a 401
 * HttpException, and a user without the required permission gets a 403
 * HttpException. The routing pipeline renders both through ExceptionRenderer.
 * The required permission is only put in the exception's context for logs,
 * never in the client-facing message.
 */
readonly class AdminAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private GuardInterface $guard,
        private AdminConfigInterface $adminConfig,
        private PermissionRegistryInterface $permissionRegistry,
    ) {}

    /**
     * @throws ReflectionException|HttpException
     */
    public function handle(
        Request $request,
        callable $next,
    ): Response {
        if (!$this->guard->check()) {
            if (!$request->wantsJson()) {
                return Response::redirect($this->adminConfig->getRoutePrefix() . '/login');
            }

            throw HttpException::unauthorized('Unauthorized.');
        }

        $requiredPermission = $this->getRequiredPermission($request);

        if ($requiredPermission !== null) {
            $user = $this->guard->user();

            if (!$user instanceof AdminUserInterface) {
                throw $this->forbidden(
                    "The authenticated user is not an admin user, so it cannot hold the required permission '$requiredPermission'.",
                );
            }

            if (!$this->userHasPermission($user, $requiredPermission)) {
                throw $this->forbidden("Admin user lacks the required permission '$requiredPermission'.");
            }
        }

        return $next($request);
    }

    /**
     * The context goes to logs only; the client sees the generic message.
     */
    private function forbidden(
        string $context,
    ): HttpException {
        return new HttpException(
            statusCode: 403,
            message: 'Forbidden.',
            context: $context,
        );
    }

    private function userHasPermission(
        AdminUserInterface $user,
        string $requiredPermission,
    ): bool {
        // Super admin bypass is handled by AdminUser::hasPermission()
        if ($user->hasPermission($requiredPermission)) {
            return true;
        }

        // Check wildcard patterns: iterate user's permission keys as patterns
        return array_any(
            $user->getPermissionKeys(),
            fn (string $permissionKey): bool => $this->permissionRegistry->matches(
                $permissionKey,
                $requiredPermission,
            ),
        );
    }

    /**
     * @throws ReflectionException
     */
    private function getRequiredPermission(Request $request): ?string
    {
        $controller = $request->controller();
        $action = $request->action();

        if ($controller === null || $action === null) {
            return null;
        }

        $reflection = new ReflectionMethod($controller, $action);
        $attributes = $reflection->getAttributes(RequiresPermission::class);

        if (empty($attributes)) {
            return null;
        }

        return $attributes[0]->newInstance()->permission;
    }
}
