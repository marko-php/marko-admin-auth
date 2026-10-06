<?php

declare(strict_types=1);

namespace Marko\AdminAuth\Entity;

interface AdminUserRoleInterface
{
    public function getUserId(): int;

    public function getRoleId(): int;
}
