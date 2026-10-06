<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Repository;

use Marko\Database\Connection\ConnectionInterface;
use Marko\Database\Connection\TransactionInterface;
use Throwable;

/**
 * Replaces one owner's rows in a two-column pivot table, for RoleRepository::syncPermissions() and
 * AdminUserRepository::syncRoles().
 *
 * The DELETE and the chunked multi-row INSERTs run through transaction(), so a failure part-way through
 * leaves the owner's previous rows in place. Inside a caller's transaction the replace runs in a savepoint:
 * a failure undoes only its own changes, and the caller can catch it and still commit.
 *
 * Table and column names are interpolated into the SQL, so they must be code constants, never input.
 *
 * @internal
 */
class PivotSync
{
    public const int ROWS_PER_CHUNK = 500;

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    /**
     * @param array<int> $relatedIds
     * @throws Throwable
     */
    public function replace(
        string $table,
        string $ownerColumn,
        int $ownerId,
        string $relatedColumn,
        array $relatedIds,
    ): void {
        $replace = function () use ($table, $ownerColumn, $ownerId, $relatedColumn, $relatedIds): void {
            $this->connection->execute("DELETE FROM $table WHERE $ownerColumn = ?", [$ownerId]);

            foreach (array_chunk($relatedIds, self::ROWS_PER_CHUNK) as $chunk) {
                $placeholders = implode(', ', array_fill(0, count($chunk), '(?, ?)'));
                $bindings = [];

                foreach ($chunk as $relatedId) {
                    $bindings[] = $ownerId;
                    $bindings[] = $relatedId;
                }

                $this->connection->execute(
                    "INSERT INTO $table ($ownerColumn, $relatedColumn) VALUES $placeholders",
                    $bindings,
                );
            }
        };

        // Same fallback as Repository::insertBatch(): a connection without transactions (only test doubles;
        // both drivers have them) runs the statements directly, with no atomicity.
        if ($this->connection instanceof TransactionInterface) {
            $this->connection->transaction($replace);

            return;
        }

        $replace();
    }
}
