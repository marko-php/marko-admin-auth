<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Repository;

use Marko\AdminAuth\Entity\AdminUser;
use Marko\AdminAuth\Entity\Role;
use Marko\Database\Repository\RepositoryInterface;
use Throwable;

/**
 * Interface for AdminUser entity repository.
 */
interface AdminUserRepositoryInterface extends RepositoryInterface
{
    /**
     * Find an admin user by email address. Emails are stored and compared in lowercase.
     */
    public function findByEmail(
        string $email,
    ): ?AdminUser;

    /**
     * Get all roles for a user.
     *
     * @return array<Role>
     */
    public function getRolesForUser(
        int $userId,
    ): array;

    /**
     * Sync roles for a user, replacing all existing.
     *
     * Atomic: the delete and the batched inserts run in one transaction, so a failure (an unknown or repeated
     * role id, a lost connection) leaves the user's previous roles in place. Inside a caller's transaction the
     * sync runs in a savepoint; a failure undoes only the sync's changes, and the caller can catch it and still
     * commit.
     *
     * @param array<int> $roleIds
     * @throws Throwable
     */
    public function syncRoles(
        int $userId,
        array $roleIds,
    ): void;
}
