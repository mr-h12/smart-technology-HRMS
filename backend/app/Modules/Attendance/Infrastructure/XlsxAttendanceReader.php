<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure;

use App\Modules\Attendance\Domain\AttendanceRefusal;
use App\Modules\Attendance\Domain\AttendanceRefused;
use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Exception\OpenSpoutException;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Reader\XLSX\Sheet;
use Throwable;

/** Reads an uploaded attendance grid (AttendanceTemplate's file, as Excel saved it back: `attendance` and `versions`) or site workbook (its day sheets and `lists`), every cell normalised. */
final class XlsxAttendanceReader
{
    /** Excel's last row. The reader walks every row number up to the last row, so a row past this one stops a crafted file early. */
    private const EXCEL_ROWS = 1048576;

    /** Excel's date serials: 1900-01-01 .. 9999-12-31. */
    private const SERIALS = [1, 2958465];

    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /**
     * The header is row 1 of `attendance`; the rows are its other non-blank rows, with their Excel row numbers. `versions` is that
     * sheet's row 1 then its non-blank rows. A missing sheet reads as empty; trailing blank cells are dropped.
     *
     * @return array{header: list<?string>, rows: list<array{row: int, cells: list<?string>}>, versions: list<list<?string>>}
     */
    public function read(string $path, int $maxRows): array
    {
        $sheets = array_column($this->each($path, fn (Sheet $sheet) => in_array($sheet->getName(), ['attendance', 'versions'], true)
            ? ['name' => $sheet->getName(), 'rows' => $this->rows($sheet, $maxRows)] : null), 'rows', 'name');

        $attendance = $sheets['attendance'] ?? [];
        $versions = $sheets['versions'] ?? [];
        $rows = [];
        foreach ($attendance as $n => $cells) {
            if ($n > 1) {
                $rows[] = ['row' => $n, 'cells' => $cells];
            }
        }

        return ['header' => $attendance[1] ?? [], 'rows' => $rows, 'versions' => [$versions[1] ?? [], ...array_values(array_diff_key($versions, [1 => true]))]];
    }

    /**
     * The site workbook (W2): every visible sheet below its two head rows, $timeColumns (0-based) read as times from row 3 (W8), and
     * `lists` rows 1–3 only, all head rows so D2 and D3 read alike (the site and range; the name list is rebuilt from the database),
     * whatever its visibility. Other hidden sheets are skipped. Rows are keyed by Excel row number, blank rows left out. A control
     * character (Excel writes a NUL as `_x0000_`) reads as a space: PostgreSQL refuses a NUL in text and in the audit row's jsonb.
     * A file with more than $maxSheets of these sheets is template_mismatch before the next one's rows are read.
     *
     * @param  list<int>  $timeColumns
     * @return list<array{name: string, visible: bool, rows: array<int, list<?string>>}>
     */
    public function sheets(string $path, int $maxRows, array $timeColumns, int $maxSheets): array
    {
        $count = 0;

        return $this->each($path, function (Sheet $sheet) use ($maxRows, $timeColumns, $maxSheets, &$count): ?array {
            $lists = $sheet->getName() === 'lists';
            if (! $lists && ! $sheet->isVisible()) {
                return null;
            }
            if (++$count > $maxSheets) {
                throw AttendanceRefused::because(AttendanceRefusal::TemplateMismatch);
            }

            return $lists
                ? ['name' => 'lists', 'visible' => $sheet->isVisible(), 'rows' => $this->rows($sheet, $maxRows, 3, [], 3, true)]
                : ['name' => $sheet->getName(), 'visible' => true, 'rows' => $this->rows($sheet, $maxRows, 2, $timeColumns, null, true)];
        });
    }

    /** Trimmed, inner whitespace collapsed, Arabic-Indic and Eastern digits as ASCII; empty is null. */
    public static function text(string $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', strtr($value, self::DIGITS)));

        return $value === '' ? null : $value;
    }

    /**
     * Opens the file and maps each sheet, in workbook order, through $read, keeping what is not null. An OpenSpout error is unreadable_file.
     *
     * @template T
     *
     * @param  Closure(Sheet): (T|null)  $read
     * @return list<T>
     */
    private function each(string $path, Closure $read): array
    {
        // Empty rows are kept so the iterator's key is the Excel row number (G8).
        // ponytail: a crafted <dimension> (A1:XFD…) makes OpenSpout build that many cells per row, which can exhaust memory_limit
        // (a 500, nothing written); Excel never writes one. Parse the sheet XML ourselves if that ever matters.
        $reader = new Reader(new Options(SHOULD_PRESERVE_EMPTY_ROWS: true));
        $sheets = [];
        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                $kept = $read($sheet);
                if ($kept !== null) {
                    $sheets[] = $kept;
                }
            }
            $reader->close();
        } catch (Throwable $e) {
            try {
                $reader->close();
            } catch (Throwable) {
                // The first error is the one to report.
            }

            throw $e instanceof OpenSpoutException ? AttendanceRefused::because(AttendanceRefusal::UnreadableFile) : $e;
        }

        return $sheets;
    }

    /**
     * Rows 1..$headRows are head rows (a date there reads as Y-m-d) and do not count toward $maxRows; $timeColumns read as times below them.
     * $noControls reads a control character as a space (the site workbook; the per-type file reads as it always has).
     *
     * @param  list<int>  $timeColumns
     * @return array<int, list<?string>> Excel row number => cells, blank rows left out, nothing after $lastRow
     */
    private function rows(Sheet $sheet, int $maxRows, int $headRows = 1, array $timeColumns = [], ?int $lastRow = null, bool $noControls = false): array
    {
        $rows = [];
        $data = 0;
        foreach ($sheet->getRowIterator() as $n => $row) {
            if ($n > self::EXCEL_ROWS) {
                throw AttendanceRefused::because(AttendanceRefusal::UnreadableFile);
            }
            if ($lastRow !== null && $n > $lastRow) {
                break;
            }
            $values = [];
            foreach ($row->cells as $i => $cell) {
                $v = self::value($cell, $n <= $headRows, $n > $headRows && in_array($i, $timeColumns, true));
                $values[$i] = $noControls && $v !== null ? self::text((string) preg_replace('/\p{Cc}/u', ' ', $v)) : $v;
            }
            $cells = $values === [] ? [] : array_replace(array_fill(0, max(array_keys($values)) + 1, null), $values);
            while ($cells !== [] && end($cells) === null) {
                array_pop($cells);
            }
            if ($cells === []) {
                continue;
            }
            if ($n > $headRows && ++$data > $maxRows) {
                throw AttendanceRefused::because(AttendanceRefusal::TooManyRows, ['max' => (string) $maxRows]);
            }
            $rows[$n] = array_values($cells);
        }

        return $rows;
    }

    /**
     * A header date that Excel turned into a date cell or a serial reads as Y-m-d (G7). A time cell holding what Excel makes of a
     * typed time (a date, a fraction of a day, a duration) reads as HH:MM (W8). Any other cell reads as the text Excel shows.
     */
    private static function value(Cell $cell, bool $header, bool $time = false): ?string
    {
        // A formula reads as its last computed value; a string starting with "=" comes back as a formula without one.
        $v = $cell instanceof FormulaCell ? ($cell->getComputedValue() ?? $cell->getValue()) : $cell->getValue();
        if ($time && (is_int($v) || is_float($v)) && $v >= 0 && $v < 1) {
            $minutes = (int) round($v * 1440);

            return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        }

        return self::text(match (true) {
            $v === null => '',
            $time && $v instanceof DateTimeInterface => $v->format('H:i'),
            $time && $v instanceof DateInterval && $v->d === 0 && $v->invert === 0 => $v->format('%H:%I'),
            $v instanceof DateTimeInterface => $v->format('Y-m-d'),
            is_int($v), is_float($v) => $header && $v >= self::SERIALS[0] && $v <= self::SERIALS[1]
                ? (new DateTimeImmutable('1899-12-30'))->modify('+'.(int) floor($v).' days')->format('Y-m-d')
                : (string) $v,
            is_bool($v) => $v ? 'TRUE' : 'FALSE',
            $v instanceof DateInterval => $v->format('%d %H:%I:%S'),
            is_string($v) => $v,
            default => implode('', array_map(fn ($run) => $run->text, $v)),   // rich text runs
        });
    }
}
