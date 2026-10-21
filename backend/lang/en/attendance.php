<?php

return [
    'refusal' => [
        'concurrency_conflict' => 'Someone else changed this record. Load the latest version and try again.',
        'period_locked' => 'This attendance period is locked and cannot be edited.',
        'future_date' => 'Attendance cannot be recorded for a future date.',
        'employee_not_active' => 'The employee is not employed on this date.',
        'missing_days_unconfirmed' => 'Some days in this period are missing; confirm to lock anyway.',
        'period_already_locked' => 'The period is already locked.',
        'period_not_locked' => 'The period is not locked.',
        'leave_type_missing' => 'No active leave type has the code :code.',
        'template_mismatch' => 'The file does not match this period\'s attendance template.',
        'unreadable_file' => 'The file could not be read.',
        'too_many_rows' => 'The file has more than :max rows.',
    ],
    'rule' => [
        'field_not_allowed' => 'This field does not apply to this day.',
        'out_of_bounds' => 'The value is outside the allowed range.',
        'site_required' => 'Choose a site or a day type.',
        'site_not_allowed' => 'Office staff are not recorded on a work site.',
        'site_inactive' => 'This site is inactive.',
        'out_before_in' => 'The out time is before the in time.',
        'times_need_both' => 'Enter both the in and out times.',
    ],
    'invalid_period' => 'This is not a period start for this group.',
    'template' => [
        'code' => 'Employee ID',
        'name' => 'Name',
        'present' => 'Present',
    ],
    'leave_name' => [
        'friday' => 'Friday',
        'saturday' => 'Saturday',
        'official_holiday' => 'Official holiday',
        'sick' => 'Sick leave',
        'rest' => 'Rest',
        'absent' => 'Absent',
        'other' => 'Other',
    ],
    'import' => [
        'unknown_employee' => 'Unknown employee ID.',
        'unknown_site' => 'Unknown site or day type.',
        'duplicate_row' => 'This employee appears twice in the file.',
    ],
];
