<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure;

use App\Modules\Attendance\Domain\AttendanceRefusal;
use App\Modules\Attendance\Domain\AttendanceRefused;
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

/** Reads an uploaded attendance grid (AttendanceTemplate's file, as Excel saved it back): its `attendance` and `versions` sheets, every cell normalised. */
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
        // Empty rows are kept so the iterator's key is the Excel row number (G8).
        // ponytail: a crafted <dimension> (A1:XFD…) makes OpenSpout build that many cells per row, which can exhaust memory_limit
        // (a 500, nothing written); Excel never writes one. Parse the sheet XML ourselves if that ever matters.
        $reader = new Reader(new Options(SHOULD_PRESERVE_EMPTY_ROWS: true));
        $sheets = [];
        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                if (in_array($sheet->getName(), ['attendance', 'versions'], true)) {
                    $sheets[$sheet->getName()] = $this->rows($sheet, $maxRows);
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

    /** Trimmed, inner whitespace collapsed, Arabic-Indic and Eastern digits as ASCII; empty is null. */
    public static function text(string $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', strtr($value, self::DIGITS)));

        return $value === '' ? null : $value;
    }

    /** @return array<int, list<?string>> Excel row number => cells, blank rows left out */
    private function rows(Sheet $sheet, int $maxRows): array
    {
        $rows = [];
        $data = 0;
        foreach ($sheet->getRowIterator() as $n => $row) {
            if ($n > self::EXCEL_ROWS) {
                throw AttendanceRefused::because(AttendanceRefusal::UnreadableFile);
            }
            $values = [];
            foreach ($row->cells as $i => $cell) {
                $values[$i] = self::value($cell, $n === 1);
            }
            $cells = $values === [] ? [] : array_replace(array_fill(0, max(array_keys($values)) + 1, null), $values);
            while ($cells !== [] && end($cells) === null) {
                array_pop($cells);
            }
            if ($cells === []) {
                continue;
            }
            if ($n > 1 && ++$data > $maxRows) {
                throw AttendanceRefused::because(AttendanceRefusal::TooManyRows, ['max' => (string) $maxRows]);
            }
            $rows[$n] = array_values($cells);
        }

        return $rows;
    }

    /** A header date that Excel turned into a date cell or a serial reads as Y-m-d (G7); any other cell reads as the text Excel shows. */
    private static function value(Cell $cell, bool $header): ?string
    {
        // A formula reads as its last computed value; a string starting with "=" comes back as a formula without one.
        $v = $cell instanceof FormulaCell ? ($cell->getComputedValue() ?? $cell->getValue()) : $cell->getValue();

        return self::text(match (true) {
            $v === null => '',
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
