<?php

declare(strict_types=1);

use Marko\AdminAuth\Exceptions\AdminAuthException;
use Marko\AdminAuth\IdentifierFormat;

it('accepts lowercase dotted permission keys', function (string $key): void {
    expect(IdentifierFormat::isPermissionKey($key))->toBeTrue();
})->with([
    'single segment' => ['dashboard'],
    'dotted' => ['blog.posts.edit'],
    'digits, underscores and hyphens' => ['catalog.product_types-2.view'],
]);

it('accepts the global wildcard and wildcard segments after the first', function (string $key): void {
    expect(IdentifierFormat::isPermissionKey($key))->toBeTrue();
})->with([
    'global wildcard' => ['*'],
    'trailing wildcard' => ['blog.*'],
    'partial segment wildcard' => ['blog.posts.ed*'],
    'middle wildcard' => ['blog.*.edit'],
]);

it(
    'rejects permission keys with uppercase letters, spaces, accents, empty segments or a trailing newline',
    function (string $key): void {
        expect(IdentifierFormat::isPermissionKey($key))->toBeFalse();
    },
)->with([
    'empty' => [''],
    'uppercase' => ['Posts.Edit'],
    'space' => ['posts.edit '],
    'inner space' => ['posts edit'],
    'accent' => ['pösts.edit'],
    'empty segment' => ['posts..edit'],
    'leading dot' => ['.posts'],
    'trailing dot' => ['posts.'],
    'trailing newline' => ["posts.edit\n"],
]);

it('rejects a wildcard in the first segment of a dotted key', function (string $key): void {
    expect(IdentifierFormat::isPermissionKey($key))->toBeFalse();
})->with([
    ['*.edit'],
    ['po*.edit'],
    ['po*'],
]);

it('accepts lowercase dotted role slugs and rejects uppercase, wildcards and whitespace', function (): void {
    expect(IdentifierFormat::isRoleSlug('editor'))->toBeTrue()
        ->and(IdentifierFormat::isRoleSlug('super-admin'))->toBeTrue()
        ->and(IdentifierFormat::isRoleSlug('catalog.content_editor'))->toBeTrue()
        ->and(IdentifierFormat::isRoleSlug('Editor'))->toBeFalse()
        ->and(IdentifierFormat::isRoleSlug('editor*'))->toBeFalse()
        ->and(IdentifierFormat::isRoleSlug('*'))->toBeFalse()
        ->and(IdentifierFormat::isRoleSlug('editor '))->toBeFalse()
        ->and(IdentifierFormat::isRoleSlug("editor\n"))->toBeFalse()
        ->and(IdentifierFormat::isRoleSlug(''))->toBeFalse();
});

it('names the key and the declaring class in invalidPermissionKey', function (): void {
    $exception = AdminAuthException::invalidPermissionKey('Posts.Edit', 'App\\Admin\\PostsSection');

    expect($exception->getMessage())->toContain("'Posts.Edit'")
        ->and($exception->getMessage())->toContain("'App\\Admin\\PostsSection'")
        ->and($exception->getSuggestion())->toContain('posts.edit');
});

it('names the key without a class in invalidPermissionKey when the class is unknown', function (): void {
    $exception = AdminAuthException::invalidPermissionKey('Posts.Edit');

    expect($exception->getMessage())->toBe("Permission key 'Posts.Edit' is not a valid permission key");
});

it('names the slug in invalidRoleSlug', function (): void {
    $exception = AdminAuthException::invalidRoleSlug('Content Editor');

    expect($exception->getMessage())->toContain("'Content Editor'")
        ->and($exception->getSuggestion())->toContain('lowercase');
});
