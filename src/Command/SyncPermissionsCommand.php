<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Command;

use Marko\AdminAuth\Contracts\PermissionRegistryInterface;
use Marko\AdminAuth\Events\PermissionsSynced;
use Marko\AdminAuth\Repository\PermissionRepositoryInterface;
use Marko\AdminAuth\Repository\PermissionSyncResult;
use Marko\AdminAuth\Repository\UnregisteredPermission;
use Marko\Core\Attributes\Command;
use Marko\Core\Command\CommandInterface;
use Marko\Core\Command\Input;
use Marko\Core\Command\Output;
use Marko\Core\Event\EventDispatcherInterface;
use Marko\Database\Command\DestructiveCommandGuard;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Writes the permissions registered at boot (every #[AdminPermission], plus any
 * registered by hand) to the permissions table, so they can be assigned to roles.
 *
 * Inserts missing permissions and updates changed labels and groups. Permissions in the table
 * that are no longer registered are reported with the number of roles holding them, and deleted
 * (with their role assignments) only with --prune. Keys containing "*" are wildcard grants and are
 * never deleted.
 *
 * --prune follows DestructiveCommandGuard: development and testing run it (asking first when
 * someone can answer); every other environment, production included, needs --force and still
 * asks when someone can answer.
 *
 * Boot never touches the database, so run this after deploying code that changes permissions.
 *
 * @noinspection PhpUnused
 */
#[Command(
    name: 'admin-auth:permissions:sync',
    description: 'Write the registered admin permissions to the database (--prune deletes unregistered ones)',
    flags: ['prune', 'force'],
    destructive: true,
)]
readonly class SyncPermissionsCommand implements CommandInterface
{
    public function __construct(
        private PermissionRegistryInterface $permissionRegistry,
        private PermissionRepositoryInterface $permissionRepository,
        private DestructiveCommandGuard $destructiveCommandGuard,
        private EventDispatcherInterface $eventDispatcher,
        private ClockInterface $clock,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(
        Input $input,
        Output $output,
    ): int {
        $result = $this->permissionRepository->syncFromRegistry($this->permissionRegistry);

        $this->report($result, $output);

        $exitCode = 0;
        $pruned = [];

        if ($input->hasOption('prune')) {
            [$exitCode, $pruned] = $this->prune($result, $input, $output);
        } elseif ($result->unregistered !== []) {
            $output->writeLine('Re-run with --prune to delete them and their role assignments.');
        }

        $this->eventDispatcher->dispatch(new PermissionsSynced(
            createdCount: $result->createdCount(),
            totalCount: $result->registeredCount,
            timestamp: $this->clock->now(),
            updatedCount: $result->updatedCount(),
            unregisteredCount: $result->unregisteredCount(),
            prunedCount: count($pruned),
        ));

        return $exitCode;
    }

    private function report(
        PermissionSyncResult $result,
        Output $output,
    ): void {
        $unchanged = $result->registeredCount - $result->createdCount() - $result->updatedCount();

        $output->writeLine(
            "Synced $result->registeredCount registered permission(s): {$result->createdCount()} created, "
            . "{$result->updatedCount()} updated, $unchanged unchanged.",
        );

        if ($result->unregistered !== []) {
            $output->writeLine(
                "{$result->unregisteredCount()} permission(s) in the database are no longer registered:",
            );

            foreach ($result->unregistered as $permission) {
                $output->writeLine("  $permission->key (held by $permission->roleCount role(s))");
            }
        }

        if ($result->wildcardKeys !== []) {
            $output->writeLine('Wildcard grants kept: ' . implode(', ', $result->wildcardKeys));
        }
    }

    /**
     * @return array{int, list<UnregisteredPermission>} The exit code and the permissions removed
     * @throws Throwable
     */
    private function prune(
        PermissionSyncResult $result,
        Input $input,
        Output $output,
    ): array {
        if ($result->unregistered === []) {
            $output->writeLine('No unregistered permissions to prune.');

            return [0, []];
        }

        $refusal = $this->destructiveCommandGuard->check(
            'admin-auth:permissions:sync --prune',
            "deletes {$result->unregisteredCount()} unregistered permission(s) and their role assignments",
            $input,
            $output,
            allowInProduction: true,
            confirmInDevelopment: true,
        );

        if ($refusal !== null) {
            return [$refusal, []];
        }

        $pruned = $this->permissionRepository->pruneUnregistered($this->permissionRegistry);

        $output->writeLine('Pruned ' . count($pruned) . ' unregistered permission(s):');

        foreach ($pruned as $permission) {
            $output->writeLine("  $permission->key (removed from $permission->roleCount role(s))");
        }

        return [0, $pruned];
    }
}
