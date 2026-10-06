<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Exceptions;

use Marko\Core\Exceptions\MarkoException;
use Throwable;

class AdminAuthException extends MarkoException
{
    /**
     * @param string|null $existingClass Section class that declared the key first, when known
     * @param string|null $duplicateClass Section class that declared it again, when known
     */
    public static function duplicatePermission(
        string $key,
        ?string $existingClass = null,
        ?string $duplicateClass = null,
    ): self {
        $message = $existingClass !== null && $duplicateClass !== null
            ? "Permission with key '$key' is declared by both '$existingClass' and '$duplicateClass'"
            : "Permission with key '$key' is already registered";

        return new self(
            message: $message,
            context: "While registering permission '$key'",
            suggestion: 'Ensure each permission has a unique key',
        );
    }

    /**
     * A section's #[AdminPermission] key was already registered by hand before boot registered it.
     */
    public static function permissionAlreadyRegistered(
        string $key,
        string $sectionClass,
        ?Throwable $previous = null,
    ): self {
        return new self(
            message: "Permission with key '$key' declared by #[AdminPermission] on '$sectionClass' is already registered",
            context: "While registering the #[AdminPermission] entries of admin section '$sectionClass' at boot",
            suggestion: '#[AdminPermission] entries are registered automatically at boot. Remove the manual '
                . "PermissionRegistryInterface::register() call for '$key', or give one of them a different key",
            previous: $previous,
        );
    }
}
