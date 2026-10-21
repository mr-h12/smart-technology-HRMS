<?php

use App\Modules\Identity\Infrastructure\Eloquent\User;

return [
    'defaults' => [
        'guard' => 'api',
    ],

    'guards' => [
        // Bearer token -> user_sessions row (BearerSessionResolver, registered in AppServiceProvider).
        'api' => [
            'driver' => 'hrms-bearer-session',
            'provider' => 'users',
        ],
        // Kept for framework plumbing; nothing signs in through it.
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],
    ],
];
