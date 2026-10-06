<?php

declare(strict_types=1);

namespace Marko\AdminAuth;

use Marko\AdminAuth\Config\AdminAuthConfigInterface;
use Marko\Authentication\AuthManager;
use Marko\Authentication\Contracts\GuardInterface;
use Marko\Authentication\Exceptions\AuthException;
use Marko\Config\Exceptions\ConfigNotFoundException;

/**
 * Resolves the guard the admin area authenticates with: the guard named by
 * admin-auth.guard ('admin' by default), never the app's default guard. The
 * admin guard has its own user provider (AdminUserProvider) and its own
 * session key, so a frontend login never opens the admin area and an admin
 * login never logs into the frontend.
 */
readonly class AdminGuardResolver
{
    public function __construct(
        private AuthManager $authManager,
        private AdminAuthConfigInterface $adminAuthConfig,
    ) {}

    /**
     * @throws AuthException|ConfigNotFoundException
     */
    public function guard(): GuardInterface
    {
        return $this->authManager->guard($this->adminAuthConfig->getGuardName());
    }
}
