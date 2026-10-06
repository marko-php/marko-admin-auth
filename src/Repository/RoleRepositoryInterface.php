<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Repository;

use Marko\AdminAuth\Entity\Permission;
use Marko\AdminAuth\Entity\Role;
use Marko\AdminAuth\Exceptions\AdminAuthException;
use Marko\Database\Exceptions\EntityException;
use Marko\Database\Repository\RepositoryInterface;
use Throwable;

/**
 * Interface for Role entity repository.
 */
interface RoleRepositoryInterface extends RepositoryInterface
{
    /**
     * Find a role by its slug. Returns null for a slug outside IdentifierFormat::ROLE_SLUG_PATTERN.
     */
    public function findBySlug(
        string $slug,
    ): ?Role;

    /**
     * Get all permissions for a role.
     *
     * @return array<Permission>
     * @throws EntityException
     */
    public function getPermissionsForRole(
        int $roleId,
    ): array;

    /**
     * Get the deduplicated permission set across all given role ids in a single query.
     * Returns an empty array without querying when $roleIds is empty.
     *
     * @param array<int> $roleIds
     * @return array<Permission>
     * @throws EntityException
     */
    public function getPermissionsForRoles(
        array $roleIds,
    ): array;

    /**
     * Sync permissions for a role, replacing all existing.
     *
     * Atomic: the delete and the batched inserts run in one transaction, so a role is never left half-synced.
     * Inside a caller's transaction the sync runs in a savepoint; a failure undoes only the sync's changes, and
     * the caller can catch it and still commit.
     *
     * @param array<int> $permissionIds
     * @throws Throwable
     */
    public function syncPermissions(
        int $roleId,
        array $permissionIds,
    ): void;

    /**
     * Check if a slug is unique within the roles table.
     *
     * @param string $slug The slug to check
     * @param int|null $excludeId Optional role ID to exclude (for updates)
     * @throws AdminAuthException When the slug is outside IdentifierFormat::ROLE_SLUG_PATTERN
     */
    public function isSlugUnique(
        string $slug,
        ?int $excludeId = null,
    ): bool;
}
