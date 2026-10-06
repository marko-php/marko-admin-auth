<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Tests\Integration;

use Marko\AdminAuth\AdminUserProvider;
use Marko\AdminAuth\Entity\AdminUser;
use Marko\AdminAuth\Entity\Permission;
use Marko\AdminAuth\Entity\Role;
use Marko\AdminAuth\Entity\RolePermission;
use Marko\AdminAuth\Repository\AdminUserRepository;
use Marko\AdminAuth\Repository\PermissionRepository;
use Marko\AdminAuth\Repository\RoleRepository;
use Marko\Authentication\Contracts\PasswordHasherInterface;
use Marko\Database\Connection\ConnectionInterface;
use Marko\Database\Diff\SchemaDiff;
use Marko\Database\Diff\SqlGeneratorInterface;
use Marko\Database\Entity\EntityHydrator;
use Marko\Database\Entity\EntityMetadataFactory;
use Marko\Database\Entity\SchemaBuilder;

/**
 * Builds the admin-auth tables on a real server for the PostgreSQL and MySQL/MariaDB integration tests, and the
 * repositories that use them.
 *
 * roles, permissions, role_permissions and admin_users are created from their entities through the driver's
 * generator, in foreign-key order. admin_user_roles has no entity (and the package migration is MySQL-only DDL),
 * so it is created with portable SQL.
 */
class AdminAuthSchema
{
    /** @var list<string> Tables in reverse dependency order, the order they are dropped in */
    private const array TABLES = ['admin_user_roles', 'role_permissions', 'admin_users', 'roles', 'permissions'];

    /** @var list<class-string> Entities in foreign-key order, the order they are created in */
    private const array ENTITIES = [Role::class, Permission::class, RolePermission::class, AdminUser::class];

    public static function drop(
        ConnectionInterface $connection,
    ): void {
        foreach (self::TABLES as $table) {
            $connection->execute('DROP TABLE IF EXISTS ' . $connection->quoteIdentifier($table));
        }
    }

    public static function create(
        ConnectionInterface $connection,
        SqlGeneratorInterface $generator,
    ): void {
        $metadataFactory = new EntityMetadataFactory();
        $schemaBuilder = new SchemaBuilder();

        foreach (self::ENTITIES as $entityClass) {
            $table = $schemaBuilder->build($metadataFactory->parse($entityClass));

            foreach ($generator->generateUp(new SchemaDiff(tablesToCreate: [$table->name => $table])) as $statement) {
                $connection->execute($statement);
            }
        }

        $connection->execute(sprintf(
            'CREATE TABLE %s (%s INT NOT NULL, %s INT NOT NULL)',
            $connection->quoteIdentifier('admin_user_roles'),
            $connection->quoteIdentifier('user_id'),
            $connection->quoteIdentifier('role_id'),
        ));
    }

    public static function permissionRepository(
        ConnectionInterface $connection,
    ): PermissionRepository {
        $metadataFactory = new EntityMetadataFactory();

        return new PermissionRepository($connection, $metadataFactory, new EntityHydrator($metadataFactory));
    }

    public static function roleRepository(
        ConnectionInterface $connection,
    ): RoleRepository {
        $metadataFactory = new EntityMetadataFactory();

        return new RoleRepository($connection, $metadataFactory, new EntityHydrator($metadataFactory));
    }

    public static function adminUserRepository(
        ConnectionInterface $connection,
    ): AdminUserRepository {
        $metadataFactory = new EntityMetadataFactory();

        return new AdminUserRepository($connection, $metadataFactory, new EntityHydrator($metadataFactory));
    }

    public static function userProvider(
        ConnectionInterface $connection,
        PasswordHasherInterface $passwordHasher,
    ): AdminUserProvider {
        return new AdminUserProvider(
            userRepository: self::adminUserRepository($connection),
            roleRepository: self::roleRepository($connection),
            passwordHasher: $passwordHasher,
        );
    }

    /**
     * Save a role and give it the permissions with the given keys.
     *
     * @param list<string> $permissionKeys
     */
    public static function roleWith(
        ConnectionInterface $connection,
        string $slug,
        array $permissionKeys,
    ): Role {
        $roleRepository = self::roleRepository($connection);
        $permissionRepository = self::permissionRepository($connection);

        $role = new Role();
        $role->name = ucfirst($slug);
        $role->slug = $slug;
        $roleRepository->save($role);

        $roleRepository->syncPermissions(
            (int) $role->id,
            array_map(
                fn (string $key): int => (int) $permissionRepository->findByKey($key)?->id,
                $permissionKeys,
            ),
        );

        return $role;
    }

    /**
     * Save a permission row directly, as a role-editing UI would for a wildcard grant.
     */
    public static function permission(
        ConnectionInterface $connection,
        string $key,
        string $label,
        string $group,
    ): void {
        $permission = new Permission();
        $permission->key = $key;
        $permission->label = $label;
        $permission->group = $group;

        self::permissionRepository($connection)->save($permission);
    }

    /**
     * The number of role_permissions rows pointing at a permission id.
     */
    public static function assignmentCount(
        ConnectionInterface $connection,
        int $permissionId,
    ): int {
        $rows = $connection->query(
            sprintf(
                'SELECT COUNT(*) AS assignments FROM %s WHERE %s = ?',
                $connection->quoteIdentifier('role_permissions'),
                $connection->quoteIdentifier('permission_id'),
            ),
            [$permissionId],
        );

        return (int) $rows[0]['assignments'];
    }
}
