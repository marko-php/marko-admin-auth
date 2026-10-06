<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Events;

use DateTimeImmutable;
use Marko\Core\Event\Event;

/**
 * Dispatched by `admin-auth:permissions:sync` after it writes the registered permissions to the database.
 *
 * The counts say what the run changed: permissions created and updated, permissions in the table that are no
 * longer registered (wildcard grants excluded), and how many of those a `--prune` run deleted.
 */
class PermissionsSynced extends Event
{
    public function __construct(
        private readonly int $createdCount,
        private readonly int $totalCount,
        private readonly DateTimeImmutable $timestamp,
        private readonly int $updatedCount = 0,
        private readonly int $unregisteredCount = 0,
        private readonly int $prunedCount = 0,
    ) {}

    public function getCreatedCount(): int
    {
        return $this->createdCount;
    }

    /**
     * The number of registered permissions.
     */
    public function getTotalCount(): int
    {
        return $this->totalCount;
    }

    public function getTimestamp(): DateTimeImmutable
    {
        return $this->timestamp;
    }

    /**
     * The number of existing permissions whose label or group changed.
     */
    public function getUpdatedCount(): int
    {
        return $this->updatedCount;
    }

    /**
     * The number of permissions in the table that are no longer registered, excluding wildcard grants.
     */
    public function getUnregisteredCount(): int
    {
        return $this->unregisteredCount;
    }

    /**
     * The number of unregistered permissions deleted by --prune.
     */
    public function getPrunedCount(): int
    {
        return $this->prunedCount;
    }
}
