<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Tests\Fixtures;

use Marko\AdminAuth\AdminGuardResolver;
use Marko\Authentication\Contracts\GuardInterface;

/**
 * An AdminGuardResolver that always returns the given guard, so unit tests
 * can hand the admin area a FakeGuard without building an AuthManager.
 */
readonly class FixedAdminGuardResolver extends AdminGuardResolver
{
    /** @noinspection PhpMissingParentConstructorInspection - the parent's AuthManager is never used */
    public function __construct(
        private GuardInterface $fixedGuard,
    ) {}

    public function guard(): GuardInterface
    {
        return $this->fixedGuard;
    }
}
