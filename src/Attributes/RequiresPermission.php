<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Attributes;

use Attribute;

/**
 * Requires a permission for an admin route. On a controller class it covers
 * every action; a method-level attribute replaces the class-level one.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
readonly class RequiresPermission
{
    public function __construct(
        public string $permission,
    ) {}
}
