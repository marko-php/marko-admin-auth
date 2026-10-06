<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Repository;

use Marko\AdminAuth\Entity\AdminUser;
use Marko\AdminAuth\Entity\Role;
use Marko\AdminAuth\Events\AdminUserCreated;
use Marko\AdminAuth\Events\AdminUserUpdated;
use Marko\Database\Entity\Entity;
use Marko\Database\Exceptions\BatchInsertException;
use Marko\Database\Exceptions\EntityException;
use Marko\Database\Exceptions\RepositoryException;
use Marko\Database\Repository\Repository;
use Throwable;

/**
 * @extends Repository<AdminUser>
 */
class AdminUserRepository extends Repository implements AdminUserRepositoryInterface
{
    protected const string ENTITY_CLASS = AdminUser::class;

    /**
     * Find an admin user by email address, compared in lowercase.
     *
     * Emails are stored lowercased by save(), so the lookup finds the same row on every driver whatever the
     * column's collation.
     */
    public function findByEmail(
        string $email,
    ): ?AdminUser {
        return $this->findOneBy(['email' => mb_strtolower($email)]);
    }

    /**
     * Get all roles for a user.
     *
     * @return array<Role>
     * @throws EntityException
     */
    public function getRolesForUser(
        int $userId,
    ): array {
        $sql = 'SELECT r.* FROM roles r
            INNER JOIN admin_user_roles aur ON r.id = aur.role_id
            WHERE aur.user_id = ?';

        $rows = $this->connection->query($sql, [$userId]);

        $roleMetadata = $this->metadataFactory->parse(Role::class);

        return array_map(
            fn (array $row): Role => $this->hydrator->hydrate(
                Role::class,
                $row,
                $roleMetadata,
            ),
            $rows,
        );
    }

    /**
     * Sync roles for a user, replacing all existing.
     *
     * @param array<int> $roleIds
     */
    public function syncRoles(
        int $userId,
        array $roleIds,
    ): void {
        // Remove all existing roles for this user
        $sql = 'DELETE FROM admin_user_roles WHERE user_id = ?';
        $this->connection->execute($sql, [$userId]);

        // Attach the new roles
        foreach ($roleIds as $roleId) {
            $sql = 'INSERT INTO admin_user_roles (user_id, role_id) VALUES (?, ?)';
            $this->connection->execute($sql, [$userId, $roleId]);
        }
    }

    /**
     * Save an admin user with its email lowercased, dispatching appropriate events.
     *
     * Lowercasing makes the unique email index reject a case variant of an existing address on every driver,
     * not only on MySQL/MariaDB's case-insensitive collations.
     *
     * @throws RepositoryException
     */
    public function save(
        Entity $entity,
    ): void {
        if (!$entity instanceof AdminUser) {
            parent::save($entity);

            return;
        }

        $entity->email = mb_strtolower($entity->email);
        $isNew = $entity->id === null;

        parent::save($entity);

        $this->dispatchSaveEvent($entity, $isNew);
    }

    /**
     * Insert admin users with their emails lowercased, as save() stores them.
     *
     * @param array<Entity> $entities
     * @throws BatchInsertException|RepositoryException|Throwable
     */
    public function insertBatch(
        array $entities,
    ): void {
        foreach ($entities as $entity) {
            if ($entity instanceof AdminUser) {
                $entity->email = mb_strtolower($entity->email);
            }
        }

        parent::insertBatch($entities);
    }

    private function dispatchSaveEvent(
        AdminUser $user,
        bool $isNew,
    ): void {
        if ($this->eventDispatcher === null) {
            return;
        }

        if ($isNew) {
            $this->eventDispatcher->dispatch(new AdminUserCreated(
                user: $user,
                timestamp: $this->now(),
            ));
        } else {
            $this->eventDispatcher->dispatch(new AdminUserUpdated(
                user: $user,
                timestamp: $this->now(),
            ));
        }
    }
}
