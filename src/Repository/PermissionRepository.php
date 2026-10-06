<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Repository;

use Marko\AdminAuth\Contracts\PermissionRegistryInterface;
use Marko\AdminAuth\Entity\Permission;
use Marko\Database\Repository\Repository;

/**
 * @extends Repository<Permission>
 */
class PermissionRepository extends Repository implements PermissionRepositoryInterface
{
    protected const string ENTITY_CLASS = Permission::class;

    /**
     * Find a permission by its key.
     */
    public function findByKey(
        string $key,
    ): ?Permission {
        return $this->findOneBy(['key' => $key]);
    }

    /**
     * Find all permissions in a group.
     *
     * The group column is a reserved word; findBy() quotes it through the connection, so this works on every
     * driver.
     *
     * @return array<Permission>
     */
    public function findByGroup(
        string $group,
    ): array {
        return $this->findBy(['group' => $group])->toArray();
    }

    /**
     * Sync permissions from the registry to the database.
     *
     * Creates new permissions that exist in the registry but not in the database.
     * Preserves existing permissions.
     *
     * @return int The number of permissions created
     */
    public function syncFromRegistry(PermissionRegistryInterface $registry): int
    {
        $created = 0;

        foreach ($registry->all() as $registered) {
            $existing = $this->findByKey($registered->key);

            if ($existing !== null) {
                continue;
            }

            $permission = new Permission();
            $permission->key = $registered->key;
            $permission->label = $registered->label;
            $permission->group = $registered->group;

            $this->save($permission);
            $created++;
        }

        return $created;
    }
}
