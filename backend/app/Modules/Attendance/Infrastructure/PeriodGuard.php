<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure;

use App\Modules\Attendance\Domain\AttendanceRefusal;
use App\Modules\Attendance\Domain\AttendanceRefused;
use App\Modules\Attendance\Domain\Period;
use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceLock;
use Illuminate\Support\Facades\DB;

/** Writes take the period's advisory lock shared, so a lock (exclusive) waits for them and they see its result. */
final class PeriodGuard
{
    /** Call inside the caller's transaction: the lock is released at its commit. */
    public static function assertOpen(Period $p): void
    {
        DB::select('select pg_advisory_xact_lock_shared(hashtextextended(?, 0))', [self::key($p)]);
        if (self::isLocked($p)) {
            throw AttendanceRefused::because(AttendanceRefusal::PeriodLocked);
        }
    }

    /** The lock-taker's form: waits for every open write on the period and blocks new ones until the caller's transaction ends. */
    public static function exclusive(Period $p): void
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [self::key($p)]);
    }

    public static function isLocked(Period $p): bool
    {
        return AttendanceLock::where(['employee_type' => $p->group->value, 'period_start' => $p->start])->whereNull('reopened_at')->exists();
    }

    private static function key(Period $p): string
    {
        return 'attendance:'.$p->group->value.':'.$p->start;
    }
}
