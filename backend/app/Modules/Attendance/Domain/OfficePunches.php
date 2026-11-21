<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain;

/**
 * Reduces one office employee's punches on one date to that day's in and out times and flags (FP-3, spec "The FP-3 seam").
 * Times are truncated to H:i, never rounded, and every shift comparison uses the truncated minute; only the
 * double-tap window is measured in seconds. A day whose punches all fall inside the window is a one-punch day.
 */
final class OfficePunches
{
    /** A technical constant, not a setting (spec, "FP-0's calls", Double tap). */
    public const DOUBLE_TAP_SECONDS = 120;

    /** @param  non-empty-list<string>  $times  distinct H:i:s in any order; $shiftStart and $shiftEnd are H:i */
    public static function reduce(array $times, string $shiftStart, string $shiftEnd): PunchedDay
    {
        sort($times);
        $earliest = $times[0];
        $latest = $times[count($times) - 1];
        $in = self::hhmm($earliest);
        $out = self::hhmm($latest);

        if (self::seconds($latest) - self::seconds($earliest) < self::DOUBLE_TAP_SECONDS) {
            $earliestMinutes = DayRules::minutes($earliest);
            $closerToStart = abs($earliestMinutes - DayRules::minutes($shiftStart)) < abs($earliestMinutes - DayRules::minutes($shiftEnd));
            if ($closerToStart) {
                $out = null;
            } else {
                $in = null;
            }
        }

        $flags = [];
        if ($in === null || $out === null) {
            $flags[] = PunchFlag::MissingPunch;
        }
        if ($out !== null && DayRules::minutes($out) < DayRules::minutes($shiftEnd)) {
            $flags[] = PunchFlag::EarlyLeave;
        }

        return new PunchedDay($in, $out, $flags, count($times));
    }

    /** The H:i of an H:i:s time, seconds cut off. */
    private static function hhmm(string $time): string
    {
        return substr($time, 0, 5);
    }

    private static function seconds(string $time): int
    {
        return DayRules::minutes($time) * 60 + (int) substr($time, 6, 2);
    }
}
