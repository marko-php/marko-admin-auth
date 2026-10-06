<?php

declare(strict_types=1);

use Marko\AdminAuth\Command\SyncPermissionsCommand;
use Marko\AdminAuth\PermissionRegistry;
use Marko\AdminAuth\Repository\PermissionRepositoryInterface;
use Marko\Core\Attributes\Command;
use Marko\Core\Command\Input;
use Marko\Core\Command\Output;

/**
 * Run the command and return its exit code and output.
 *
 * @return array{int, string}
 */
function runSyncPermissionsCommand(
    SyncPermissionsCommand $command,
): array {
    $stream = fopen('php://memory', 'r+');
    $exitCode = $command->execute(new Input(['marko', 'admin-auth:permissions:sync']), new Output($stream));
    rewind($stream);

    return [$exitCode, (string) stream_get_contents($stream)];
}

it('is registered as the admin-auth:permissions:sync command', function (): void {
    $command = new ReflectionClass(SyncPermissionsCommand::class)->getAttributes(Command::class)[0]->newInstance();

    expect($command->name)->toBe('admin-auth:permissions:sync')
        ->and($command->description)->not->toBeEmpty();
});

it('syncs the registered permissions to the database', function (): void {
    $registry = new PermissionRegistry();
    $registry->register(key: 'blog.posts.view', label: 'View Posts', group: 'blog');

    $repository = $this->createMock(PermissionRepositoryInterface::class);
    $repository->expects($this->once())
        ->method('syncFromRegistry')
        ->with($this->identicalTo($registry))
        ->willReturn(1);

    [$exitCode] = runSyncPermissionsCommand(new SyncPermissionsCommand($registry, $repository));

    expect($exitCode)->toBe(0);
});

it('reports how many permissions were created and how many are registered', function (): void {
    $registry = new PermissionRegistry();
    $registry->register(key: 'blog.posts.view', label: 'View Posts', group: 'blog');
    $registry->register(key: 'blog.posts.edit', label: 'Edit Posts', group: 'blog');
    $registry->register(key: 'blog.posts.delete', label: 'Delete Posts', group: 'blog');

    $repository = $this->createMock(PermissionRepositoryInterface::class);
    $repository->method('syncFromRegistry')->willReturn(2);

    [, $output] = runSyncPermissionsCommand(new SyncPermissionsCommand($registry, $repository));

    expect($output)->toBe("Synced 3 registered permission(s): 2 created, 1 already in the database.\n");
});
