<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain;

/**
 * Which dates a site manager may write in full (design Part 2, P8): today, and yesterday until the `manager_yesterday_until` time,
 * Cairo. After that a past day takes only the departure of an open day (owner, 2026-11-05), and that write is a past-day edit HR
 * hears of at once. Dates are Y-m-d and times H:i, so string comparison is chronological. SiteDay can widen this for the employee whose record HR returned.
 */
final class ManagerWindow
{
    public static function allows(string $date, string $today, string $now, string $until): bool
    {
        return $date === $today || ($date === self::yesterday($today) && $now < $until);
    }

    /** A write to a day before today outside the yesterday window: the departure of an open day, which HR is told of at once. */
    public static function isPastDayEdit(string $date, string $today, string $now, string $until): bool
    {
        return $date < $today && ! self::allows($date, $today, $now, $until);
    }

    public static function yesterday(string $today): string
    {
        return (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
    }
}
