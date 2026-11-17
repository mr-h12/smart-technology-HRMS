<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain;

/** Why a fingerprint file row gave no punches; the value is the API error code. The reader gives every case, FutureDate for a row dated after the $today it is given. */
enum PunchRowError: string
{
    case BadDate = 'bad_date';
    case BadDeviceUserId = 'bad_device_user_id';
    case MissingName = 'missing_name';
    case BadTime = 'bad_time';
    case FutureDate = 'future_date';
}
