<?php

declare(strict_types=1);

use Marko\AdminAuth\Command\SyncPermissionsCommand;
use Marko\AdminAuth\Events\PermissionsSynced;
use Marko\AdminAuth\PermissionRegistry;
use Marko\AdminAuth\Repository\PermissionRepository;
use Marko\AdminAuth\Tests\Fixtures\SqlitePermissionConnection;
use Marko\Core\Attributes\Command;
use Marko\Core\Command\Input;
use Marko\Core\Command\Output;
use Marko\Core\Environment\AppEnvironment;
use Marko\Database\Command\DestructiveCommandGuard;
use Marko\Database\Entity\EntityHydrator;
use Marko\Database\Entity\EntityMetadataFactory;
use Marko\Testing\Fake\FakeClock;
use Marko\Testing\Fake\FakeConfirmationPrompter;
use Marko\Testing\Fake\FakeEventDispatcher;

/**
 * Run the command against the test's SQLite permissions table and return its exit code and output.
 *
 * @param list<string> $options
 * @return array{int, string}
 */
function runSyncPermissions(
    object $test,
    string $environment = 'development',
    array $options = [],
    ?FakeConfirmationPrompter $prompter = null,
): array {
    $metadataFactory = new EntityMetadataFactory();
    $command = new SyncPermissionsCommand(
        permissionRegistry: $test->registry,
        permissionRepository: new PermissionRepository(
            $test->connection,
            $metadataFactory,
            new EntityHydrator($metadataFactory),
        ),
        destructiveCommandGuard: new DestructiveCommandGuard(
            appEnvironment: new AppEnvironment(['APP_ENV' => $environment]),
            confirmationPrompter: $prompter ?? new FakeConfirmationPrompter(interactive: false),
        ),
        eventDispatcher: $test->events,
        clock: new FakeClock('2026-10-06 12:00:00'),
    );

    $stream = fopen('php://memory', 'r+');
    $exitCode = $command->execute(
        new Input(['marko', 'admin-auth:permissions:sync', ...$options])->withFlags(['prune', 'force']),
        new Output($stream),
    );
    rewind($stream);

    return [$exitCode, (string) stream_get_contents($stream)];
}

beforeEach(function (): void {
    $this->connection = new SqlitePermissionConnection();
    $this->registry = new PermissionRegistry();
    $this->events = new FakeEventDispatcher();

    $this->registry->register('blog.posts.view', 'View Posts', 'blog');
    $this->registry->register('blog.posts.edit', 'Edit Posts', 'blog');
    $this->connection->addPermission('blog.posts.view', 'Old Label', 'blog');
    $this->connection->addPermission('legacy.export', 'Export', 'legacy');
    $this->connection->addPermission('catalog.*', 'All Catalog', 'catalog');
    $this->connection->grant(1, 'legacy.export');
    $this->connection->grant(2, 'legacy.export');
    $this->connection->grant(1, 'catalog.*');
});

it('is registered as the admin-auth:permissions:sync command', function (): void {
    $command = new ReflectionClass(SyncPermissionsCommand::class)->getAttributes(Command::class)[0]->newInstance();

    expect($command->name)->toBe('admin-auth:permissions:sync')
        ->and($command->description)->not->toBeEmpty();
});

it('declares prune and force as value-less flags', function (): void {
    $command = new ReflectionClass(SyncPermissionsCommand::class)->getAttributes(Command::class)[0]->newInstance();

    expect($command->flags)->toEqualCanonicalizing(['prune', 'force']);
});

it('reports unregistered permissions with role counts and deletes nothing without --prune', function (): void {
    [$exitCode, $output] = runSyncPermissions($this);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('1 permission(s) in the database are no longer registered:')
        ->and($output)->toContain('  legacy.export (held by 2 role(s))')
        ->and($output)->toContain('Re-run with --prune to delete them and their role assignments.')
        ->and($this->connection->statements('DELETE'))->toBe([])
        ->and($this->connection->keysForRole(2))->toBe(['legacy.export']);
});

it('reports updated labels and groups', function (): void {
    [, $output] = runSyncPermissions($this);

    expect($output)->toContain('Synced 2 registered permission(s): 1 created, 1 updated, 0 unchanged.')
        ->and($this->connection->permissions()['blog.posts.view']['label'])->toBe('View Posts');
});

it('lists wildcard grants as kept', function (): void {
    [, $output] = runSyncPermissions($this);

    expect($output)->toContain('Wildcard grants kept: catalog.*')
        ->and($output)->not->toContain('  catalog.* (held by');
});

it('prunes in development when nobody can answer', function (): void {
    [$exitCode, $output] = runSyncPermissions($this, options: ['--prune']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Pruned 1 unregistered permission(s):')
        ->and($output)->toContain('  legacy.export (removed from 2 role(s))')
        ->and(array_keys($this->connection->permissions()))->toBe(['blog.posts.edit', 'blog.posts.view', 'catalog.*'])
        ->and($this->connection->keysForRole(2))->toBe([])
        ->and($this->connection->keysForRole(1))->toBe(['catalog.*']);
});

it('asks before pruning in development when interactive', function (): void {
    $prompter = new FakeConfirmationPrompter(answers: [true]);

    [$exitCode] = runSyncPermissions($this, options: ['--prune'], prompter: $prompter);

    expect($exitCode)->toBe(0)
        ->and(array_keys($this->connection->permissions()))->not->toContain('legacy.export');
    $prompter->assertAsked(
        'admin-auth:permissions:sync --prune deletes 1 unregistered permission(s) and their role assignments '
        . "in the 'development' environment. Continue?",
    );
});

it('refuses --prune without --force in staging and production', function (string $environment): void {
    $prompter = new FakeConfirmationPrompter();

    [$exitCode, $output] = runSyncPermissions($this, $environment, ['--prune'], $prompter);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain("is refused in the '$environment' environment without --force")
        ->and(array_keys($this->connection->permissions()))->toContain('legacy.export')
        ->and($this->connection->keysForRole(2))->toBe(['legacy.export']);
    $prompter->assertNothingAsked();
})->with(['staging', 'production']);

it('cancels the prune when the confirmation is declined with --force', function (): void {
    $prompter = new FakeConfirmationPrompter(answers: [false]);

    [$exitCode, $output] = runSyncPermissions($this, 'production', ['--prune', '--force'], $prompter);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('cancelled.')
        ->and(array_keys($this->connection->permissions()))->toContain('legacy.export');
});

it('prunes with --force when nobody can answer in production', function (): void {
    [$exitCode, $output] = runSyncPermissions($this, 'production', ['--prune', '--force']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Pruned 1 unregistered permission(s):')
        ->and(array_keys($this->connection->permissions()))->not->toContain('legacy.export')
        ->and(array_keys($this->connection->permissions()))->toContain('catalog.*');
});

it('skips the guard when --prune finds nothing to remove', function (): void {
    $this->registry->register('legacy.export', 'Export', 'legacy');
    $prompter = new FakeConfirmationPrompter();

    [$exitCode, $output] = runSyncPermissions($this, 'production', ['--prune'], $prompter);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('No unregistered permissions to prune.')
        ->and($output)->not->toContain('Error');
    $prompter->assertNothingAsked();
});

it('dispatches PermissionsSynced with created, updated, unregistered and pruned counts', function (): void {
    runSyncPermissions($this, options: ['--prune']);

    $events = $this->events->dispatched(PermissionsSynced::class);

    expect($events)->toHaveCount(1)
        ->and($events[0]->getCreatedCount())->toBe(1)
        ->and($events[0]->getUpdatedCount())->toBe(1)
        ->and($events[0]->getTotalCount())->toBe(2)
        ->and($events[0]->getUnregisteredCount())->toBe(1)
        ->and($events[0]->getPrunedCount())->toBe(1)
        ->and($events[0]->getTimestamp()->format('Y-m-d H:i:s'))->toBe('2026-10-06 12:00:00');
});

it(
    'still dispatches PermissionsSynced with a zero pruned count when the prune is refused or cancelled',
    function (string $environment, array $options, array $answers): void {
        runSyncPermissions($this, $environment, $options, new FakeConfirmationPrompter(answers: $answers));

        $events = $this->events->dispatched(PermissionsSynced::class);

        expect($events)->toHaveCount(1)
            ->and($events[0]->getUnregisteredCount())->toBe(1)
            ->and($events[0]->getPrunedCount())->toBe(0);
    },
)->with([
    'refused' => ['staging', ['--prune'], []],
    'cancelled' => ['staging', ['--prune', '--force'], [false]],
]);
