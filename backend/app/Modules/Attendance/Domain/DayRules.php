<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain;

use App\Modules\Employees\Domain\EmployeeType;

/** What a day may hold per employee type, and the minutes computed from its times (docs/calculations.md §1–4). Times are HH:MM wall-clock. */
final class DayRules
{
    public const MAX_MINUTES = 960;

    public const FIELDS = [
        'office' => ['in_time', 'out_time'],
        'engineer' => ['in_time', 'out_time', 'late_minutes', 'ot_minutes', 'outside_minutes', 'advance_amount', 'note'],
        'permanent_worker' => ['scheduled_in', 'scheduled_out', 'in_time', 'out_time', 'outside_amount', 'transport', 'advance_amount', 'note'],
        'day_laborer' => ['ot_minutes', 'late_minutes', 'advance_amount', 'note'],
    ];

    public const LEAVE_DAY_FIELDS = ['advance_amount', 'note'];

    /**
     * @param  array{kind: string, outside_alexandria: bool, active: bool}|null  $site
     * @param  array<string, int|string>  $fields  only the non-null values
     * @return list<array{field: string, code: string}>
     */
    public static function violations(EmployeeType $type, ?array $site, array $fields): array
    {
        $violations = [];
        if ($site === null) {
            if ($type !== EmployeeType::Office) {
                $violations[] = ['field' => 'site_id', 'code' => 'site_required'];
            }
        } elseif ($type === EmployeeType::Office && $site['kind'] !== 'leave') {
            $violations[] = ['field' => 'site_id', 'code' => 'site_not_allowed'];
        } elseif (! $site['active']) {
            $violations[] = ['field' => 'site_id', 'code' => 'site_inactive'];
        }

        // Later checks see only the fields that passed field_not_allowed.
        $accepted = [];
        foreach ($fields as $field => $value) {
            if (! self::allows($type, $site, $field, $value)) {
                $violations[] = ['field' => $field, 'code' => 'field_not_allowed'];
            } elseif (self::outOfBounds($field, $value)) {
                $violations[] = ['field' => $field, 'code' => 'out_of_bounds'];
            } else {
                $accepted[$field] = $value;
            }
        }

        $in = $accepted['in_time'] ?? null;
        $out = $accepted['out_time'] ?? null;
        if ($type === EmployeeType::PermanentWorker) {
            if (($in === null) !== ($out === null)) {
                $violations[] = ['field' => $in === null ? 'in_time' : 'out_time', 'code' => 'times_need_both'];
            }
        } elseif ($in !== null && $out !== null && self::minutes((string) $out) < self::minutes((string) $in)) {
            $violations[] = ['field' => 'out_time', 'code' => 'out_before_in'];
        }

        return $violations;
    }

    public static function officeLateMinutes(string $in, string $shiftStart, int $graceMinutes): int
    {
        return max(0, self::minutes($in) - self::minutes($shiftStart) - $graceMinutes);
    }

    /**
     * An out time earlier than the in time is a night shift that crossed midnight.
     *
     * @param  numeric-string  $dayHours
     * @return array{late: int, ot: int}
     */
    public static function workerMinutes(string $scheduledIn, string $in, string $out, string $dayHours): array
    {
        $worked = self::minutes($out) - self::minutes($in);
        if ($worked < 0) {
            $worked += 1440;
        }

        return [
            'late' => max(0, self::minutes($in) - self::minutes($scheduledIn)),
            'ot' => max(0, $worked - (int) bcmul($dayHours, '60', 6)),
        ];
    }

    /**
     * Whether the type takes this field on this site (null: office present); ignores bounds and the site's active flag.
     *
     * @param  array{kind: string, outside_alexandria: bool, active: bool}|null  $site
     */
    public static function allows(EmployeeType $type, ?array $site, string $field, int|string $value): bool
    {
        if (! in_array($field, self::FIELDS[$type->value], true)) {
            return false;
        }
        if ($site !== null && $site['kind'] === 'leave') {
            return in_array($field, self::LEAVE_DAY_FIELDS, true) || ($field === 'transport' && $value === 'none');
        }

        return ! str_starts_with($field, 'outside_') || ($site['outside_alexandria'] ?? false);
    }

    private static function outOfBounds(string $field, int|string $value): bool
    {
        if (str_ends_with($field, '_minutes')) {
            return $value < 0 || $value > self::MAX_MINUTES;
        }
        if (str_ends_with($field, '_amount')) {
            $amount = (string) $value;

            return ! is_numeric($amount) || bccomp($amount, '0', 6) <= 0;
        }

        return false;
    }

    private static function minutes(string $time): int
    {
        [$h, $m] = explode(':', $time);

        return (int) $h * 60 + (int) $m;
    }
}
