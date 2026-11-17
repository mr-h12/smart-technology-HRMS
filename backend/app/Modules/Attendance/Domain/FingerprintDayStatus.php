<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain;

/** What confirming the fingerprint import does to one employee's day; the value is the API status. Only Conflict and Leave take HR's tick. */
enum FingerprintDayStatus: string
{
    case New = 'new';
    case Refresh = 'refresh';
    case Unchanged = 'unchanged';
    case Conflict = 'conflict';
    case Leave = 'leave';
    case Locked = 'locked';
    case NotOffice = 'not_office';
}
