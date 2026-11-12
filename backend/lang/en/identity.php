<?php

return [
    'refusal' => [
        'invalid_credentials' => 'These credentials do not match our records.',
        'account_suspended' => 'This account has been deactivated. Please contact HR.',
        'account_locked' => 'This account is locked after too many failed sign-in attempts. Contact administration.',
        'permission_denied' => 'You do not have permission to perform this action.',
        'rate_limited' => 'Too many attempts. Try again shortly.',
        'not_viewing_as' => 'You are not viewing as another account.',
        'account_out_of_reach' => 'You cannot manage an account, or give a role, that holds permissions you do not have.',
    ],
    'view_as' => [
        'self' => 'You cannot view as your own account.',
        'not_allowed' => 'You cannot view as an account that can itself view as others.',
        'inactive' => 'This account is deactivated or locked.',
    ],
    'last_role_manager' => 'At least one active account must keep the right to manage roles.',
];
