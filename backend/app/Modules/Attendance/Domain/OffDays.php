<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain;

use App\Modules\Sites\Domain\LeaveCode;
use DateTimeImmutable;

final class OffDays
{
    /**
     * The leave day-type code a date takes by itself: a holiday, else its weekday name when that is a weekly off.
     * Any weekday may be a weekly off; one with no matching leave type is still returned (the lock refuses it).
     *
     * @param  list<string>  $weeklyOff  lowercase English weekday names
     */
    public static function leaveCodeFor(string $date, array $weeklyOff, bool $isHoliday): ?string
    {
        if ($isHoliday) {
            return LeaveCode::OfficialHoliday->value;
        }
        $weekday = strtolower((new DateTimeImmutable($date))->format('l'));

        return in_array($weekday, $weeklyOff, true) ? $weekday : null;
    }
}
