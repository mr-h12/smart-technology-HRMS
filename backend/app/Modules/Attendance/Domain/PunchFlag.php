<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain;

/** What the fingerprint import tells HR about an office day it builds; derived from the day's times, never stored. */
enum PunchFlag: string
{
    case MissingPunch = 'missing_punch';
    case EarlyLeave = 'early_leave';
}
