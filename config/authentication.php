<?php

declare(strict_types=1);

use Marko\AdminAuth\AdminUserProvider;

/*
|--------------------------------------------------------------------------
| Admin Guard
|--------------------------------------------------------------------------
|
| Merged into marko/authentication's config. The admin area authenticates
| with the 'admin' guard (admin-auth.guard), which loads admin users through
| AdminUserProvider and keeps its own session key, separate from the
| frontend's default guard.
|
*/
return [
    'guards' => [
        'admin' => [
            'driver' => 'session',
            'provider' => 'admins',
        ],
    ],
    'providers' => [
        'admins' => [
            'class' => AdminUserProvider::class,
        ],
    ],
];
