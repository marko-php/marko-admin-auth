<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Tests\Unit\Attributes;

use Attribute;
use Marko\AdminAuth\Attributes\RequiresPermission;
use ReflectionClass;

it('creates RequiresPermission targeting classes and methods with a permission key', function (): void {
    $reflection = new ReflectionClass(RequiresPermission::class);
    $attributes = $reflection->getAttributes(Attribute::class);

    expect($attributes)->toHaveCount(1);

    $attributeInstance = $attributes[0]->newInstance();
    expect($attributeInstance->flags)->toBe(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD);

    $permission = new RequiresPermission('posts.create');
    expect($permission->permission)->toBe('posts.create');
});

it('instantiates a RequiresPermission placed on a controller class', function (): void {
    $controller = new #[RequiresPermission('settings.manage')] class () {};

    $attributes = new ReflectionClass($controller)->getAttributes(RequiresPermission::class);

    expect($attributes)->toHaveCount(1)
        ->and($attributes[0]->newInstance()->permission)->toBe('settings.manage');
});
