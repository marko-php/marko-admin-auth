<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Tests\Integration;

use Marko\Authentication\Contracts\PasswordHasherInterface;

/**
 * A real bcrypt hasher (at the lowest cost) for the login tests, so a stored hash is verified as at sign-in.
 */
readonly class NativePasswordHasher implements PasswordHasherInterface
{
    public function hash(
        string $password,
    ): string {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);
    }

    public function verify(
        string $password,
        string $hash,
    ): bool {
        return password_verify($password, $hash);
    }

    public function needsRehash(
        string $hash,
    ): bool {
        return false;
    }

    public function verifyDummy(
        string $password,
    ): void {
        password_verify($password, '$2y$04$ZxSPF.27pFWWE6Ew0jDzE.HiJNsq8davb61hvjFFesMqHyLDmjNbe');
    }
}
