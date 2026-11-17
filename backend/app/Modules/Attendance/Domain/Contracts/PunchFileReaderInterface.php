<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Contracts;

use App\Modules\Attendance\Domain\Punch;
use App\Modules\Attendance\Domain\PunchRowError;

/** Reads the fingerprint device's Excel export (design spec, "The file"). Throws AttendanceRefused for a file it cannot use. */
interface PunchFileReaderInterface
{
    /**
     * Every sheet whose header row is the device's, column J ignored. Punches come back de-duplicated on (device user id, date, time),
     * in file order; a row with an error gives no punches and one error.
     *
     * @return array{punches: list<Punch>, errors: list<array{sheet: string, row: int, code: PunchRowError}>}
     */
    public function read(string $path, int $maxRows): array;
}
