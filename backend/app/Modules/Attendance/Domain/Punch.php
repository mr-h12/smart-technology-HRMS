<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain;

/**
 * One time the fingerprint device recorded for one person (docs/superpowers/specs/2026-11-17-fingerprint-import-design.md).
 * Not an attendance day: the import reduces a person-day's punches to one. $deviceName is the fingerprint name as the file
 * spells it; $deviceUserId is shown, never matched on.
 */
final readonly class Punch
{
    public function __construct(
        public int $deviceUserId,
        public string $deviceName,
        public string $date,
        public string $time,
    ) {}
}
