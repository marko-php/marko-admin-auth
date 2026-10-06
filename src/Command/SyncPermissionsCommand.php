<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Command;

use Marko\AdminAuth\Contracts\PermissionRegistryInterface;
use Marko\AdminAuth\Repository\PermissionRepositoryInterface;
use Marko\Core\Attributes\Command;
use Marko\Core\Command\CommandInterface;
use Marko\Core\Command\Input;
use Marko\Core\Command\Output;

/**
 * Writes the permissions registered at boot (every #[AdminPermission], plus any
 * registered by hand) to the permissions table, so they can be assigned to roles.
 *
 * Boot never touches the database, so run this after deploying code that adds permissions.
 *
 * @noinspection PhpUnused
 */
#[Command(name: 'admin-auth:permissions:sync', description: 'Write the registered admin permissions to the database')]
readonly class SyncPermissionsCommand implements CommandInterface
{
    public function __construct(
        private PermissionRegistryInterface $permissionRegistry,
        private PermissionRepositoryInterface $permissionRepository,
    ) {}

    public function execute(
        Input $input,
        Output $output,
    ): int {
        $registered = count($this->permissionRegistry->all());
        $created = $this->permissionRepository->syncFromRegistry($this->permissionRegistry);
        $existing = $registered - $created;

        $output->writeLine(
            "Synced $registered registered permission(s): $created created, $existing already in the database.",
        );

        return 0;
    }
}
