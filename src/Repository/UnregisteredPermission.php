<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Repository;

/**
 * A row in the permissions table whose key is no longer registered (and is not a wildcard grant),
 * with the number of roles that still hold it.
 */
readonly class UnregisteredPermission
{
    public function __construct(
        public int $id,
        public string $key,
        public string $label,
        public string $group,
        public int $roleCount,
    ) {}
}
