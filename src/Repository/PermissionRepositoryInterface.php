<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Repository;

use Marko\AdminAuth\Contracts\PermissionRegistryInterface;
use Marko\AdminAuth\Entity\Permission;
use Marko\Database\Repository\RepositoryInterface;
use Throwable;

/**
 * Interface for Permission entity repository.
 */
interface PermissionRepositoryInterface extends RepositoryInterface
{
    /**
     * Find a permission by its key.
     */
    public function findByKey(
        string $key,
    ): ?Permission;

    /**
     * Find all permissions in a group.
     *
     * @return array<Permission>
     */
    public function findByGroup(
        string $group,
    ): array;

    /**
     * Sync permissions from the registry to the database.
     *
     * Inserts registered permissions missing from the table and updates the label and group of existing rows
     * that changed. Reports the rows whose key is no longer registered (with the number of roles holding each)
     * and the wildcard keys (containing `*`), but deletes nothing.
     *
     * @throws Throwable
     */
    public function syncFromRegistry(
        PermissionRegistryInterface $registry,
    ): PermissionSyncResult;

    /**
     * Find the permissions whose key is no longer registered, sorted by key. Wildcard keys are never included.
     *
     * @return list<UnregisteredPermission>
     */
    public function findUnregistered(
        PermissionRegistryInterface $registry,
    ): array;

    /**
     * Delete the permissions whose key is no longer registered, and their role_permissions rows, in one
     * transaction. Keys containing `*` are wildcard grants and are never deleted.
     *
     * @return list<UnregisteredPermission> The permissions removed, with the number of roles that held each
     * @throws Throwable
     */
    public function pruneUnregistered(
        PermissionRegistryInterface $registry,
    ): array;
}
