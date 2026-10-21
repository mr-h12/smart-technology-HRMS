<?php

declare(strict_types=1);

return [
    'validation_failed' => 'The given data was invalid.',
    'not_found' => 'Resource not found.',
    'unauthenticated' => 'Unauthenticated.',
    'server_error' => 'Server error.',
    'bank_account_required' => 'Bank account is required when paying by bank transfer.',
    'pay_field_required' => ':field is required for :type.',
    'no_type_on_date' => 'The employee has no type in force on this date.',
    'duplicate_date' => 'A record already exists on this date.',
    'leave_code_required' => 'Leave code is required for leave types.',
    'leave_code_not_allowed' => 'Leave code applies to leave types only.',
    'leave_code_taken' => 'Another active leave type already uses this code.',
    'allowance_value_required' => 'Value is required unless the allowance type is "None".',
    'employee_type' => [
        'office' => 'Office employee',
        'engineer' => 'Engineer',
        'permanent_worker' => 'Permanent worker',
        'day_laborer' => 'Day laborer',
    ],
];
