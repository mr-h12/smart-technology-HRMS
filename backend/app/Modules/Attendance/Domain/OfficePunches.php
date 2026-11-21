<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain;

/**
 * Reduces one office employee's punches on one date to that day's times and flags (FP-3, spec "The FP-3 seam").
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

        if (self::seconds($latest) - self::seconds($earliest) < self::DOUBLE_TAP_SECONDS) {
            $minute = self::minutes($earliest);
            $isCheckIn = abs($minute - self::minutes($shiftStart)) < abs($minute - self::minutes($shiftEnd));
            $in = $isCheckIn ? self::truncate($earliest) : null;
            $out = $isCheckIn ? null : self::truncate($latest);
        } else {
            $in = self::truncate($earliest);
            $out = self::truncate($latest);
        }

        $flags = [];
        if ($in === null || $out === null) {
            $flags[] = PunchFlag::MissingPunch;
        }
        if ($out !== null && self::minutes($out) < self::minutes($shiftEnd)) {
            $flags[] = PunchFlag::EarlyLeave;
        }

        return new PunchedDay($in, $out, $flags, count($times));
    }

    private static function truncate(string $time): string
    {
        return substr($time, 0, 5);
    }

    /** Whole minutes since midnight of an H:i or H:i:s time, seconds dropped. */
    private static function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }

    private static function seconds(string $time): int
    {
        return self::minutes($time) * 60 + (int) substr($time, 6, 2);
    }
}
