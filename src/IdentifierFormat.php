<?php

declare(strict_types=1);

namespace Marko\AdminAuth;

/**
 * The one canonical form of the identifiers admin-auth stores in unique columns.
 *
 * MySQL and MariaDB compare strings with a case- and accent-insensitive collation by default; PostgreSQL and PHP
 * compare them exactly. Permission keys and role slugs are therefore restricted to lowercase ASCII, so whether two
 * values are "the same" never depends on the database server.
 */
class IdentifierFormat
{
    /**
     * "*", or lowercase segments of [a-z0-9_-] separated by dots. Every segment after the first may also contain
     * "*", which is a wildcard grant (blog.*, blog.posts.ed*).
     */
    public const string PERMISSION_KEY_PATTERN = '/^(?:\*|[a-z0-9_-]+(?:\.[a-z0-9_*-]+)*)$/D';

    /**
     * Lowercase segments of [a-z0-9_-] separated by dots, with no wildcard.
     */
    public const string ROLE_SLUG_PATTERN = '/^[a-z0-9_-]+(?:\.[a-z0-9_-]+)*$/D';

    public static function isPermissionKey(
        string $key,
    ): bool {
        return preg_match(self::PERMISSION_KEY_PATTERN, $key) === 1;
    }

    public static function isRoleSlug(
        string $slug,
    ): bool {
        return preg_match(self::ROLE_SLUG_PATTERN, $slug) === 1;
    }
}
