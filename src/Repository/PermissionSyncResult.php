<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Repository;

/**
 * What PermissionRepositoryInterface::syncFromRegistry() did and found.
 */
readonly class PermissionSyncResult
{
    /**
     * @param int $registeredCount The number of registered permissions
     * @param list<string> $created Keys inserted into the permissions table
     * @param list<string> $updated Keys whose label or group was updated
     * @param list<UnregisteredPermission> $unregistered Rows whose key is no longer registered (never wildcard keys)
     * @param list<string> $wildcardKeys Wildcard keys (containing `*`) in the table, which are kept and never pruned
     */
    public function __construct(
        public int $registeredCount,
        public array $created,
        public array $updated,
        public array $unregistered,
        public array $wildcardKeys,
    ) {}

    public function createdCount(): int
    {
        return count($this->created);
    }

    public function updatedCount(): int
    {
        return count($this->updated);
    }

    public function unregisteredCount(): int
    {
        return count($this->unregistered);
    }
}
