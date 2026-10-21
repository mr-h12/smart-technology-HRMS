<?php

return [
    // How long an account stays locked after the fifth failed login.
    'lockout_minutes' => (int) env('IDENTITY_LOCKOUT_MINUTES', 30),

    // Above the lockout threshold, so a person reaches the lock before the throttle.
    'rate_limit' => [
        'login' => ['attempts' => 10, 'decay_minutes' => 1],
    ],
];
