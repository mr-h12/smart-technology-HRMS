<?php

declare(strict_types=1);

namespace App\Modules\Employees\Infrastructure;

use DateInterval;
use DateTimeInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;

/** Reads every sheet of a workbook as rows keyed by the headers in its row 1 (the staging workbook the master-data importer loads). */
final class XlsxSheetRows
{
    /**
     * Sheet name => its non-blank rows after row 1, with their 1-based Excel row numbers. Cells are trimmed strings, blank is null;
     * a column without a header is left out.
     *
     * @return array<string, list<array{row: int, cells: array<string, ?string>}>>
     */
    public function read(string $path): array
    {
        // Empty rows are kept so the iterator's key is the Excel row number.
        $reader = new Reader(new Options(SHOULD_PRESERVE_EMPTY_ROWS: true));
        $reader->open($path);
        try {
            $sheets = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                $headers = [];
                $rows = [];
                foreach ($sheet->getRowIterator() as $n => $row) {
                    $values = array_map(self::value(...), $row->cells);
                    if ($n === 1) {
                        $headers = array_filter($values, fn (?string $h) => $h !== null);

                        continue;
                    }
                    $cells = [];
                    foreach ($headers as $i => $header) {
                        $cells[$header] = $values[$i] ?? null;
                    }
                    if (array_filter($cells, fn (?string $v) => $v !== null) !== []) {
                        $rows[] = ['row' => $n, 'cells' => $cells];
                    }
                }
                $sheets[$sheet->getName()] = $rows;
            }

            return $sheets;
        } finally {
            $reader->close();
        }
    }

    /**
     * Sheet name => its row-1 headers by 0-based column, blanks left out. Unlike read(), a repeated header stays visible here.
     *
     * @return array<string, array<int, string>>
     */
    public function headers(string $path): array
    {
        $reader = new Reader(new Options(SHOULD_PRESERVE_EMPTY_ROWS: true));
        $reader->open($path);
        try {
            $sheets = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                $sheets[$sheet->getName()] = [];
                foreach ($sheet->getRowIterator() as $row) {
                    $sheets[$sheet->getName()] = array_filter(array_map(self::value(...), $row->cells), fn (?string $h) => $h !== null);

                    break;
                }
            }

            return $sheets;
        } finally {
            $reader->close();
        }
    }

    private static function value(Cell $cell): ?string
    {
        // A formula reads as the value Excel last computed for it.
        $v = $cell instanceof FormulaCell ? $cell->getComputedValue() : $cell->getValue();
        $text = trim(match (true) {
            $v === null => '',
            $v instanceof DateTimeInterface => $v->format('Y-m-d'),
            is_bool($v) => $v ? '1' : '0',
            $v instanceof DateInterval => $v->format('%H:%I'),
            is_array($v) => implode('', array_map(fn ($run) => $run->text, $v)),   // rich text runs
            default => (string) $v,
        });

        return $text === '' ? null : $text;
    }
}
