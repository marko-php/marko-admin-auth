<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Repository;

use Marko\AdminAuth\Entity\Permission;
use Marko\AdminAuth\Entity\Role;
use Marko\AdminAuth\Events\RoleCreated;
use Marko\AdminAuth\Events\RoleDeleted;
use Marko\AdminAuth\Events\RoleUpdated;
use Marko\AdminAuth\Exceptions\AdminAuthException;
use Marko\AdminAuth\IdentifierFormat;
use Marko\Database\Connection\TransactionInterface;
use Marko\Database\Entity\Entity;
use Marko\Database\Exceptions\BatchInsertException;
use Marko\Database\Exceptions\EntityException;
use Marko\Database\Exceptions\RepositoryException;
use Marko\Database\Repository\Repository;
use Throwable;

/**
 * @extends Repository<Role>
 */
class RoleRepository extends Repository implements RoleRepositoryInterface
{
    protected const string ENTITY_CLASS = Role::class;

    private const int SYNC_ROWS_PER_CHUNK = 500;

    /**
     * Save a role, dispatching appropriate events.
     *
     * @throws AdminAuthException|RepositoryException
     */
    public function save(
        Entity $entity,
    ): void {
        if (!$entity instanceof Role) {
            parent::save($entity);

            return;
        }

        $this->assertCanonicalSlug($entity);

        $isNew = $entity->id === null;

        parent::save($entity);

        $this->dispatchSaveEvent($entity, $isNew);
    }

    /**
     * Insert roles whose slugs all match IdentifierFormat::ROLE_SLUG_PATTERN; nothing is inserted otherwise.
     *
     * @param array<Entity> $entities
     * @throws AdminAuthException|BatchInsertException|RepositoryException|Throwable
     */
    public function insertBatch(
        array $entities,
    ): void {
        foreach ($entities as $entity) {
            if ($entity instanceof Role) {
                $this->assertCanonicalSlug($entity);
            }
        }

        parent::insertBatch($entities);
    }

    /**
     * @throws AdminAuthException
     */
    private function assertCanonicalSlug(
        Role $role,
    ): void {
        if (!IdentifierFormat::isRoleSlug($role->slug)) {
            throw AdminAuthException::invalidRoleSlug($role->slug);
        }
    }

    /**
     * Delete a role, dispatching appropriate events.
     *
     * @throws RepositoryException
     */
    public function delete(
        Entity $entity,
    ): void {
        if (!$entity instanceof Role) {
            parent::delete($entity);

            return;
        }

        parent::delete($entity);

        $this->eventDispatcher?->dispatch(new RoleDeleted(
            role: $entity,
            timestamp: $this->now(),
        ));
    }

    private function dispatchSaveEvent(
        Role $role,
        bool $isNew,
    ): void {
        if ($this->eventDispatcher === null) {
            return;
        }

        if ($isNew) {
            $this->eventDispatcher->dispatch(new RoleCreated(
                role: $role,
                timestamp: $this->now(),
            ));
        } else {
            $this->eventDispatcher->dispatch(new RoleUpdated(
                role: $role,
                timestamp: $this->now(),
            ));
        }
    }

    /**
     * Find a role by its slug.
     *
     * A slug outside IdentifierFormat::ROLE_SLUG_PATTERN can't be stored, so it returns null without a query
     * (on MySQL/MariaDB the collation would otherwise match "Editor" to "editor").
     */
    public function findBySlug(
        string $slug,
    ): ?Role {
        if (!IdentifierFormat::isRoleSlug($slug)) {
            return null;
        }

        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Get all permissions for a role.
     *
     * @return array<Permission>
     * @throws EntityException
     */
    public function getPermissionsForRole(
        int $roleId,
    ): array {
        $sql = 'SELECT p.* FROM permissions p
            INNER JOIN role_permissions rp ON p.id = rp.permission_id
            WHERE rp.role_id = ?';

        $rows = $this->connection->query($sql, [$roleId]);

        $permissionMetadata = $this->metadataFactory->parse(Permission::class);

        return array_map(
            fn (array $row): Permission => $this->hydrator->hydrate(
                Permission::class,
                $row,
                $permissionMetadata,
            ),
            $rows,
        );
    }

    /**
     * Get the deduplicated permission set across all given role ids in a single query.
     *
     * @param array<int> $roleIds
     * @return array<Permission>
     * @throws EntityException
     */
    public function getPermissionsForRoles(
        array $roleIds,
    ): array {
        if ($roleIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($roleIds), '?'));
        $sql = "SELECT DISTINCT p.* FROM permissions p
            INNER JOIN role_permissions rp ON p.id = rp.permission_id
            WHERE rp.role_id IN ($placeholders)";

        $rows = $this->connection->query($sql, $roleIds);

        $permissionMetadata = $this->metadataFactory->parse(Permission::class);

        return array_map(
            fn (array $row): Permission => $this->hydrator->hydrate(
                Permission::class,
                $row,
                $permissionMetadata,
            ),
            $rows,
        );
    }

    /**
     * Sync permissions for a role, replacing all existing.
     *
     * The DELETE and batched INSERT run through transaction(), so a mid-sync
     * failure cannot leave a role half-synced. Inside a caller's transaction
     * the sync runs in a savepoint: a failure undoes only the sync's own
     * changes, and the caller can catch it and still commit its own work.
     *
     * @param array<int> $permissionIds
     * @throws Throwable
     */
    public function syncPermissions(
        int $roleId,
        array $permissionIds,
    ): void {
        $sync = function () use ($roleId, $permissionIds): void {
            $this->connection->execute(
                'DELETE FROM role_permissions WHERE role_id = ?',
                [$roleId],
            );

            foreach (array_chunk($permissionIds, self::SYNC_ROWS_PER_CHUNK) as $chunk) {
                $placeholders = implode(
                    ', ',
                    array_fill(0, count($chunk), '(?, ?)'),
                );
                $bindings = [];
                foreach ($chunk as $permissionId) {
                    $bindings[] = $roleId;
                    $bindings[] = $permissionId;
                }
                $this->connection->execute(
                    "INSERT INTO role_permissions (role_id, permission_id) VALUES $placeholders",
                    $bindings,
                );
            }
        };

        // Same fallback as Repository::insertBatch(): a connection without
        // transactions (only test doubles; both drivers have them) runs the
        // statements directly, with no atomicity.
        if ($this->connection instanceof TransactionInterface) {
            $this->connection->transaction($sync);

            return;
        }

        $sync();
    }

    /**
     * Check if a slug is unique within the roles table.
     *
     * @throws AdminAuthException When the slug is outside IdentifierFormat::ROLE_SLUG_PATTERN, since save() would
     *     reject it
     */
    public function isSlugUnique(
        string $slug,
        ?int $excludeId = null,
    ): bool {
        if (!IdentifierFormat::isRoleSlug($slug)) {
            throw AdminAuthException::invalidRoleSlug($slug);
        }

        return $this->isColumnUnique('slug', $slug, $excludeId);
    }
}
