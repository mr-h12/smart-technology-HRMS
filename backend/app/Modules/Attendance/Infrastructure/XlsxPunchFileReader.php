<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure;

use App\Modules\Attendance\Domain\AttendanceRefusal;
use App\Modules\Attendance\Domain\AttendanceRefused;
use App\Modules\Attendance\Domain\Contracts\PunchFileReaderInterface;
use App\Modules\Attendance\Domain\Punch;
use App\Modules\Attendance\Domain\PunchRowError;
use DateTimeImmutable;
use DateTimeInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Sheet;

/** The fingerprint device's Excel export (fingerprint design spec, "The file"): every sheet whose row 1 is the device's header, A to I. */
final class XlsxPunchFileReader implements PunchFileReaderInterface
{
    /** Row 1 of a device sheet, trimmed and lower-cased; J (`More`, a link into the LAN) is never read. */
    private const HEADER = ['date', 'id number', 'name', 'in', 'out', 'in', 'out', 'in', 'out'];

    /** The date OpenSpout gives a time cell (a fraction of a day) in the 1900 and the 1904 date systems. */
    private const TIME_DATES = ['1899-12-30', '1904-01-01'];

    public function read(string $path, int $maxRows, string $today): array
    {
        $data = 0;
        $sheets = (new XlsxAttendanceReader)->each($path, function (Sheet $sheet) use ($maxRows, $today, &$data): ?array {
            $rows = [];
            $isDeviceSheet = false;
            foreach ($sheet->getRowIterator() as $n => $row) {
                $cells = array_map(fn (int $i) => self::value($row->cells[$i] ?? null), range(0, 8));
                if (! $isDeviceSheet) {
                    if ($n !== 1 || array_map(fn ($v) => is_string($v) ? strtolower(trim($v)) : $v, $cells) !== self::HEADER) {
                        return null;
                    }
                    $isDeviceSheet = true;

                    continue;
                }
                if ($n > XlsxAttendanceReader::EXCEL_ROWS) {
                    throw AttendanceRefused::because(AttendanceRefusal::UnreadableFile);
                }
                if (array_filter($cells, fn ($v) => ! self::blank($v)) === []) {
                    continue;
                }
                if (++$data > $maxRows) {
                    throw AttendanceRefused::because(AttendanceRefusal::TooManyRows, ['max' => (string) $maxRows]);
                }
                $rows[] = ['sheet' => $sheet->getName(), 'row' => $n, 'read' => self::row($cells, $today)];
            }

            return $isDeviceSheet ? $rows : null;
        });
        if ($sheets === []) {
            throw AttendanceRefused::because(AttendanceRefusal::NotFingerprintFile);
        }

        $punches = [];
        $errors = [];
        foreach (array_merge(...$sheets) as ['sheet' => $sheet, 'row' => $row, 'read' => $read]) {
            if ($read instanceof PunchRowError) {
                $errors[] = ['sheet' => $sheet, 'row' => $row, 'code' => $read];

                continue;
            }
            foreach ($read as $punch) {
                $punches["{$punch->deviceUserId} {$punch->date} {$punch->time}"] ??= $punch;
            }
        }

        return ['punches' => array_values($punches), 'errors' => $errors];
    }

    /**
     * A row's punches, or the first of its errors in PunchRowError's order. A row with no time has no punch and no error.
     *
     * @param  list<mixed>  $cells  A to I
     * @return list<Punch>|PunchRowError
     */
    private static function row(array $cells, string $today): array|PunchRowError
    {
        $date = self::date($cells[0]);
        $id = $cells[1];
        // A control character reads as a space, as in the site workbook: PostgreSQL refuses a NUL in text. Otherwise the name is
        // kept as the file spells it (decision 1): FP-2's fingerprintKey() ignores ASCII whitespace and case, nothing else.
        $name = is_string($cells[2]) || is_int($cells[2]) ? (string) preg_replace('/\p{Cc}/u', ' ', (string) $cells[2]) : '';
        $name = XlsxAttendanceReader::text($name) === null ? null : trim($name, " \t\n\r\f\x0B");
        if ($date === null) {
            return PunchRowError::BadDate;
        }
        if (! is_int($id) || $id <= 0) {
            return PunchRowError::BadDeviceUserId;
        }
        if ($name === null) {
            return PunchRowError::MissingName;
        }
        $times = [];
        foreach (array_slice($cells, 3) as $cell) {
            if (self::blank($cell)) {
                continue;
            }
            $time = self::time($cell);
            if ($time === null) {
                return PunchRowError::BadTime;
            }
            $times[] = $time;
        }
        if ($date > $today) {
            return PunchRowError::FutureDate;
        }

        return array_map(fn (string $time) => new Punch($id, $name, $date, $time), $times);
    }

    /** A date cell or a date serial as Y-m-d. */
    private static function date(mixed $v): ?string
    {
        return match (true) {
            $v instanceof DateTimeInterface => $v->format('Y-m-d') >= '1900-01-01' ? $v->format('Y-m-d') : null,
            (is_int($v) || is_float($v)) && $v >= XlsxAttendanceReader::SERIALS[0] && $v <= XlsxAttendanceReader::SERIALS[1] => (new DateTimeImmutable('1899-12-30'))->modify('+'.(int) floor($v).' days')->format('Y-m-d'),
            default => null,
        };
    }

    /** A time cell, a fraction of a day under 1 (rounded to the second), or a typed time such as `8:05`, as H:i:s. */
    private static function time(mixed $v): ?string
    {
        if ($v instanceof DateTimeInterface) {
            return in_array($v->format('Y-m-d'), self::TIME_DATES, true) ? $v->format('H:i:s') : null;
        }
        if ((is_int($v) || is_float($v)) && $v >= 0 && $v < 1) {
            $s = min((int) round($v * 86400), 86399);

            return sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s, 60) % 60, $s % 60);
        }
        if (is_string($v) && preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', XlsxAttendanceReader::text($v) ?? '', $m)
            && (int) $m[1] < 24 && (int) $m[2] < 60 && (int) ($m[3] ?? 0) < 60) {
            return sprintf('%02d:%02d:%02d', $m[1], $m[2], $m[3] ?? 0);
        }

        return null;
    }

    private static function blank(mixed $v): bool
    {
        return $v === null || (is_string($v) && XlsxAttendanceReader::text($v) === null);
    }

    /** A formula reads as its last computed value. */
    private static function value(?Cell $cell): mixed
    {
        return $cell instanceof FormulaCell ? ($cell->getComputedValue() ?? $cell->getValue()) : $cell?->getValue();
    }
}
