<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Contracts;

use Marko\AdminAuth\Exceptions\AdminAuthException;
use Marko\AdminAuth\RegisteredPermission;

interface PermissionRegistryInterface
{
    /**
     * @throws AdminAuthException When the key is already registered
     */
    public function register(
        string $key,
        string $label,
        string $group,
    ): void;

    /**
     * @return array<RegisteredPermission>
     */
    public function all(): array;

    /**
     * @return array<RegisteredPermission>
     */
    public function getByGroup(
        string $group,
    ): array;

    /**
     * Check if a wildcard pattern matches a specific permission key.
     */
    public function matches(
        string $pattern,
        string $permissionKey,
    ): bool;
}
