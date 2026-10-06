<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Tests\Unit\Repository;

use DateTimeImmutable;
use Marko\AdminAuth\Entity\AdminUser;
use Marko\AdminAuth\Entity\AdminUserInterface;
use Marko\AdminAuth\Entity\Role;
use Marko\AdminAuth\Entity\RoleInterface;
use Marko\AdminAuth\Events\AdminUserCreated;
use Marko\AdminAuth\Events\AdminUserUpdated;
use Marko\AdminAuth\Events\RoleCreated;
use Marko\AdminAuth\Events\RoleDeleted;
use Marko\AdminAuth\Events\RoleUpdated;
use Marko\AdminAuth\Repository\AdminUserRepository;
use Marko\AdminAuth\Repository\RoleRepository;
use Marko\Database\Connection\ConnectionInterface;
use Marko\Database\Connection\StatementInterface;
use Marko\Database\Entity\EntityHydrator;
use Marko\Database\Entity\EntityMetadataFactory;
use Marko\Database\Events\EntityCreated;
use Marko\Database\Events\EntityCreating;
use Marko\Database\Events\EntityDeleted;
use Marko\Database\Events\EntityDeleting;
use Marko\Database\Events\EntityUpdated;
use Marko\Database\Events\EntityUpdating;
use Marko\Testing\Fake\FakeClock;
use Marko\Testing\Fake\FakeEventDispatcher;
use RuntimeException;

it('dispatches RoleCreated event when role is created', function (): void {
    $eventDispatcher = new FakeEventDispatcher();
    $connection = createEventMockConnection();
    $metadataFactory = new EntityMetadataFactory();
    $hydrator = new EntityHydrator();

    $repository = new RoleRepository(
        $connection,
        $metadataFactory,
        $hydrator,
        null,
        $eventDispatcher,
    );

    $role = new Role();
    $role->name = 'Editor';
    $role->slug = 'editor';

    $repository->save($role);

    $classes = array_map(fn (object $e): string => $e::class, $eventDispatcher->dispatched);

    expect($classes)->toContain(EntityCreating::class)
        ->and($classes)->toContain(EntityCreated::class)
        ->and($classes)->toContain(RoleCreated::class);

    $domainEvent = $eventDispatcher->dispatched[array_search(RoleCreated::class, $classes)];

    expect($domainEvent)->toBeInstanceOf(RoleCreated::class)
        ->and($domainEvent->getRole())->toBeInstanceOf(RoleInterface::class)
        ->and($domainEvent->getRole()->getName())->toBe('Editor');
});

it('dispatches RoleUpdated event when role is modified', function (): void {
    $eventDispatcher = new FakeEventDispatcher();
    $connection = createEventMockConnection(isNew: false);
    $metadataFactory = new EntityMetadataFactory();
    $hydrator = new EntityHydrator();

    $repository = new RoleRepository(
        $connection,
        $metadataFactory,
        $hydrator,
        null,
        $eventDispatcher,
    );

    $role = new Role();
    $role->id = 1;
    $role->name = 'Editor Updated';
    $role->slug = 'editor-updated';

    $repository->save($role);

    $classes = array_map(fn (object $e): string => $e::class, $eventDispatcher->dispatched);

    expect($classes)->toContain(EntityUpdating::class)
        ->and($classes)->toContain(EntityUpdated::class)
        ->and($classes)->toContain(RoleUpdated::class);

    $domainEvent = $eventDispatcher->dispatched[array_search(RoleUpdated::class, $classes)];

    expect($domainEvent->getRole()->getName())->toBe('Editor Updated');
});

it('dispatches RoleDeleted event when role is removed', function (): void {
    $eventDispatcher = new FakeEventDispatcher();
    $connection = createEventMockConnection(isNew: false);
    $metadataFactory = new EntityMetadataFactory();
    $hydrator = new EntityHydrator();

    $repository = new RoleRepository(
        $connection,
        $metadataFactory,
        $hydrator,
        null,
        $eventDispatcher,
    );

    $role = new Role();
    $role->id = 1;
    $role->name = 'Editor';
    $role->slug = 'editor';

    $repository->delete($role);

    $classes = array_map(fn (object $e): string => $e::class, $eventDispatcher->dispatched);

    expect($classes)->toContain(EntityDeleting::class)
        ->and($classes)->toContain(EntityDeleted::class)
        ->and($classes)->toContain(RoleDeleted::class);

    $domainEvent = $eventDispatcher->dispatched[array_search(RoleDeleted::class, $classes)];

    expect($domainEvent->getRole()->getName())->toBe('Editor');
});

it('dispatches AdminUserCreated event when user is created', function (): void {
    $eventDispatcher = new FakeEventDispatcher();
    $connection = createEventMockConnection();
    $metadataFactory = new EntityMetadataFactory();
    $hydrator = new EntityHydrator();

    $repository = new AdminUserRepository(
        $connection,
        $metadataFactory,
        $hydrator,
        null,
        $eventDispatcher,
    );

    $user = new AdminUser();
    $user->email = 'admin@example.com';
    $user->password = 'hashed_password';
    $user->name = 'Admin User';

    $repository->save($user);

    $classes = array_map(fn (object $e): string => $e::class, $eventDispatcher->dispatched);

    expect($classes)->toContain(EntityCreating::class)
        ->and($classes)->toContain(EntityCreated::class)
        ->and($classes)->toContain(AdminUserCreated::class);

    $domainEvent = $eventDispatcher->dispatched[array_search(AdminUserCreated::class, $classes)];

    expect($domainEvent)->toBeInstanceOf(AdminUserCreated::class)
        ->and($domainEvent->getUser())->toBeInstanceOf(AdminUserInterface::class)
        ->and($domainEvent->getUser()->getAuthIdentifier())->toBe(1);
});

it('dispatches AdminUserUpdated event when user is modified', function (): void {
    $eventDispatcher = new FakeEventDispatcher();
    $connection = createEventMockConnection(isNew: false);
    $metadataFactory = new EntityMetadataFactory();
    $hydrator = new EntityHydrator();

    $repository = new AdminUserRepository(
        $connection,
        $metadataFactory,
        $hydrator,
        null,
        $eventDispatcher,
    );

    $user = new AdminUser();
    $user->id = 1;
    $user->email = 'admin@example.com';
    $user->password = 'hashed_password';
    $user->name = 'Admin Updated';

    $repository->save($user);

    $classes = array_map(fn (object $e): string => $e::class, $eventDispatcher->dispatched);

    expect($classes)->toContain(EntityUpdating::class)
        ->and($classes)->toContain(EntityUpdated::class)
        ->and($classes)->toContain(AdminUserUpdated::class);

    $domainEvent = $eventDispatcher->dispatched[array_search(AdminUserUpdated::class, $classes)];

    expect($domainEvent->getUser()->getAuthIdentifier())->toBe(1);
});

it('stamps RoleCreated with the repository\'s current instant', function (): void {
    $eventDispatcher = new FakeEventDispatcher();
    $clock = new FakeClock('2026-01-01 12:00:00 UTC');
    $repository = createClockedRoleRepository($clock, createEventMockConnection(), $eventDispatcher);

    $role = new Role();
    $role->name = 'Viewer';
    $role->slug = 'viewer';

    $repository->save($role);

    expect(findDispatchedEvent($eventDispatcher, RoleCreated::class)->getTimestamp())->toEqual($clock->now());
});

it('stamps RoleUpdated with the repository\'s current instant', function (): void {
    $eventDispatcher = new FakeEventDispatcher();
    $clock = new FakeClock('2026-01-01 12:00:00 UTC');
    $repository = createClockedRoleRepository($clock, createEventMockConnection(isNew: false), $eventDispatcher);

    $role = new Role();
    $role->id = 1;
    $role->name = 'Viewer';
    $role->slug = 'viewer';

    $clock->travel('+1 hour');
    $repository->save($role);

    expect(findDispatchedEvent($eventDispatcher, RoleUpdated::class)->getTimestamp())->toEqual($clock->now());
});

it('stamps RoleDeleted with the repository\'s current instant', function (): void {
    $eventDispatcher = new FakeEventDispatcher();
    $clock = new FakeClock('2026-01-01 12:00:00 UTC');
    $repository = createClockedRoleRepository($clock, createEventMockConnection(isNew: false), $eventDispatcher);

    $role = new Role();
    $role->id = 1;
    $role->name = 'Viewer';
    $role->slug = 'viewer';

    $repository->delete($role);

    expect(findDispatchedEvent($eventDispatcher, RoleDeleted::class)->getTimestamp())->toEqual($clock->now());
});

it('stamps AdminUserCreated and AdminUserUpdated with the repository\'s current instant', function (): void {
    $clock = new FakeClock('2026-01-01 12:00:00 UTC');

    $createDispatcher = new FakeEventDispatcher();
    $user = new AdminUser();
    $user->email = 'admin@example.com';
    $user->password = 'hashed_password';
    $user->name = 'Admin User';
    createClockedAdminUserRepository($clock, createEventMockConnection(), $createDispatcher)->save($user);
    $createdAt = $clock->now();

    $clock->travel('+1 day');
    $updateDispatcher = new FakeEventDispatcher();
    $existing = new AdminUser();
    $existing->id = 1;
    $existing->email = 'admin@example.com';
    $existing->password = 'hashed_password';
    $existing->name = 'Admin Updated';
    createClockedAdminUserRepository($clock, createEventMockConnection(isNew: false), $updateDispatcher)->save($existing);

    expect(findDispatchedEvent($createDispatcher, AdminUserCreated::class)->getTimestamp())->toEqual($createdAt)
        ->and(findDispatchedEvent($updateDispatcher, AdminUserUpdated::class)->getTimestamp())->toEqual($clock->now());
});

/**
 * A RoleRepository whose current instant (the database Repository::now() seam) reads a FakeClock.
 */
function createClockedRoleRepository(
    FakeClock $clock,
    ConnectionInterface $connection,
    FakeEventDispatcher $eventDispatcher,
): RoleRepository {
    return new class ($clock, $connection, $eventDispatcher) extends RoleRepository
    {
        public function __construct(
            private readonly FakeClock $clock,
            ConnectionInterface $connection,
            FakeEventDispatcher $eventDispatcher,
        ) {
            parent::__construct($connection, new EntityMetadataFactory(), new EntityHydrator(), null, $eventDispatcher);
        }

        protected function now(): DateTimeImmutable
        {
            return $this->clock->now();
        }
    };
}

/**
 * An AdminUserRepository whose current instant (the database Repository::now() seam) reads a FakeClock.
 */
function createClockedAdminUserRepository(
    FakeClock $clock,
    ConnectionInterface $connection,
    FakeEventDispatcher $eventDispatcher,
): AdminUserRepository {
    return new class ($clock, $connection, $eventDispatcher) extends AdminUserRepository
    {
        public function __construct(
            private readonly FakeClock $clock,
            ConnectionInterface $connection,
            FakeEventDispatcher $eventDispatcher,
        ) {
            parent::__construct($connection, new EntityMetadataFactory(), new EntityHydrator(), null, $eventDispatcher);
        }

        protected function now(): DateTimeImmutable
        {
            return $this->clock->now();
        }
    };
}

function findDispatchedEvent(
    FakeEventDispatcher $eventDispatcher,
    string $class,
): object {
    foreach ($eventDispatcher->dispatched as $event) {
        if ($event instanceof $class) {
            return $event;
        }
    }

    throw new RuntimeException("No $class event was dispatched");
}

// Helper functions

function createEventMockConnection(
    bool $isNew = true,
): ConnectionInterface {
    return new readonly class ($isNew) implements ConnectionInterface
    {
        public function __construct(
            private bool $isNew,
        ) {}

        public function connect(): void {}

        public function disconnect(): void {}

        public function isConnected(): bool
        {
            return true;
        }

        public function query(
            string $sql,
            array $bindings = [],
        ): array {
            return [];
        }

        public function execute(
            string $sql,
            array $bindings = [],
        ): int {
            return 1;
        }

        public function prepare(
            string $sql,
        ): StatementInterface {
            throw new RuntimeException('Not implemented');
        }

        public function lastInsertId(): int
        {
            return $this->isNew ? 1 : 0;
        }

        public function driverName(): string
        {
            return 'sqlite';
        }
    };
}
