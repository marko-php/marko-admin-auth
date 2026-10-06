<?php

declare(strict_types=1);

use Marko\AdminAuth\Repository\PivotSync;
use Marko\AdminAuth\Tests\Fixtures\SqlitePermissionConnection;
use Marko\Database\Connection\ConnectionInterface;
use Marko\Database\Connection\StatementInterface;

beforeEach(function (): void {
    $this->connection = new SqlitePermissionConnection();
    $this->sync = new PivotSync($this->connection);
    $this->replaceRoles = fn (int $userId, array $roleIds) => $this->sync->replace(
        table: 'admin_user_roles',
        ownerColumn: 'user_id',
        ownerId: $userId,
        relatedColumn: 'role_id',
        relatedIds: $roleIds,
    );
});

it('deletes the owner\'s rows and inserts the new set in one multi-row statement', function (): void {
    ($this->replaceRoles)(1, [3]);
    ($this->replaceRoles)(2, [9]);
    $this->connection->log = [];

    ($this->replaceRoles)(1, [10, 20, 30]);

    expect($this->connection->statements('DELETE'))->toBe(['DELETE FROM "admin_user_roles" WHERE "user_id" = ?'])
        ->and($this->connection->statements('INSERT'))
        ->toBe(['INSERT INTO "admin_user_roles" ("user_id", "role_id") VALUES (?, ?), (?, ?), (?, ?)'])
        ->and($this->connection->roleIdsForUser(1))->toBe([10, 20, 30])
        ->and($this->connection->roleIdsForUser(2))->toBe([9]);
});

it('splits inserts into statements of at most 500 rows', function (): void {
    ($this->replaceRoles)(1, range(1, 1001));

    $rowsPerInsert = array_map(
        fn (string $sql): int => substr_count($sql, '(?, ?)'),
        $this->connection->statements('INSERT'),
    );

    expect(PivotSync::ROWS_PER_CHUNK)->toBe(500)
        ->and($rowsPerInsert)->toBe([500, 500, 1])
        ->and($this->connection->roleIdsForUser(1))->toBe(range(1, 1001));
});

it('only deletes when given no related ids', function (): void {
    ($this->replaceRoles)(1, [10, 20]);
    $this->connection->log = [];

    ($this->replaceRoles)(1, []);

    expect($this->connection->statements('INSERT'))->toBe([])
        ->and($this->connection->statements('DELETE'))->toHaveCount(1)
        ->and($this->connection->roleIdsForUser(1))->toBe([]);
});

it('runs the delete and inserts in one transaction when the connection supports transactions', function (): void {
    ($this->replaceRoles)(1, [10, 20]);

    expect($this->connection->log)->toBe([
        'BEGIN',
        'DELETE FROM "admin_user_roles" WHERE "user_id" = ?',
        'INSERT INTO "admin_user_roles" ("user_id", "role_id") VALUES (?, ?), (?, ?)',
        'COMMIT',
    ]);
});

it('rolls back the delete when an insert fails', function (): void {
    ($this->replaceRoles)(1, [10, 20]);

    expect(fn () => ($this->replaceRoles)(1, [30, 30]))->toThrow(PDOException::class)
        ->and($this->connection->roleIdsForUser(1))->toBe([10, 20])
        ->and($this->connection->inTransaction())->toBeFalse();
});

it('runs the statements directly when the connection does not support transactions', function (): void {
    $statements = [];
    $connection = new class ($statements) implements ConnectionInterface
    {
        /**
         * @param list<array{sql: string, bindings: array<mixed>}> $statements
         */
        public function __construct(
            /** @noinspection PhpPropertyOnlyWrittenInspection - Reference property modifies external variable */
            private array &$statements,
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
            $this->statements[] = ['sql' => $sql, 'bindings' => $bindings];

            return 1;
        }

        public function prepare(
            string $sql,
        ): StatementInterface {
            throw new RuntimeException('Not implemented');
        }

        public function lastInsertId(): int
        {
            return 0;
        }

        public function driverName(): string
        {
            return 'sqlite';
        }

        public function supportsReturning(): bool
        {
            return false;
        }

        public function quoteIdentifier(
            string $identifier,
        ): string {
            return '"' . str_replace('"', '""', $identifier) . '"';
        }
    };

    new PivotSync($connection)->replace('role_permissions', 'role_id', 4, 'permission_id', [7, 8]);

    expect($statements)->toBe([
        ['sql' => 'DELETE FROM "role_permissions" WHERE "role_id" = ?', 'bindings' => [4]],
        [
            'sql' => 'INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (?, ?), (?, ?)',
            'bindings' => [4, 7, 4, 8],
        ],
    ]);
});
