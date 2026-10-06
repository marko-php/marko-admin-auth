<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Discovery;

use Marko\Admin\Discovery\AdminSectionDefinition;
use Marko\Admin\Discovery\AdminSectionDiscovery;
use Marko\Admin\Exceptions\AdminException;
use Marko\AdminAuth\Contracts\PermissionRegistryInterface;
use Marko\AdminAuth\Exceptions\AdminAuthException;
use Marko\AdminAuth\IdentifierFormat;
use ReflectionException;

readonly class PermissionDiscovery
{
    public function __construct(
        private PermissionRegistryInterface $registry,
        private AdminSectionDiscovery $sectionDiscovery,
    ) {}

    /**
     * Discover permissions from AdminPermission attributes on an AdminSection class.
     *
     * @param class-string $className
     * @throws AdminException|AdminAuthException|ReflectionException
     */
    public function discoverFromClass(
        string $className,
    ): void {
        $this->registerFromDefinitions([$this->sectionDiscovery->parseAdminSectionClass($className)]);
    }

    /**
     * Register the #[AdminPermission] entries of already-parsed admin sections.
     *
     * Each permission is grouped by the first segment of its key. A key outside
     * IdentifierFormat::PERMISSION_KEY_PATTERN, or two sections that declare the same
     * key, fail loudly naming the section class(es) before anything is registered.
     *
     * @param array<AdminSectionDefinition> $definitions
     * @throws AdminAuthException
     */
    public function registerFromDefinitions(
        array $definitions,
    ): void {
        /** @var array<string, string> $declaredBy permission key => section class */
        $declaredBy = [];

        foreach ($definitions as $definition) {
            foreach ($definition->permissions as $permission) {
                if (!IdentifierFormat::isPermissionKey($permission->id)) {
                    throw AdminAuthException::invalidPermissionKey($permission->id, $definition->className);
                }

                if (isset($declaredBy[$permission->id])) {
                    throw AdminAuthException::duplicatePermission(
                        $permission->id,
                        $declaredBy[$permission->id],
                        $definition->className,
                    );
                }

                $declaredBy[$permission->id] = $definition->className;
            }
        }

        foreach ($definitions as $definition) {
            foreach ($definition->permissions as $permission) {
                try {
                    $this->registry->register(
                        key: $permission->id,
                        label: $permission->label,
                        group: $this->deriveGroup($permission->id),
                    );
                } catch (AdminAuthException $e) {
                    throw AdminAuthException::permissionAlreadyRegistered($permission->id, $definition->className, $e);
                }
            }
        }
    }

    private function deriveGroup(
        string $key,
    ): string {
        $parts = explode('.', $key);

        return $parts[0];
    }
}
