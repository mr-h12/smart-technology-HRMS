<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain;

/** Audit event names Attendance writes explicitly (AttendanceLock is not an Audited model). */
final class AttendanceAuditEvents
{
    public const PERIOD_LOCKED = 'ATTENDANCE_LOCKED';

    public const PERIOD_REOPENED = 'ATTENDANCE_REOPENED';
}
