<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure;

use Closure;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Validation\CellReference;
use OpenSpout\Writer\XLSX\Validation\Rules\ListValidationRule;
use OpenSpout\Writer\XLSX\Validation\ValidationDisplay;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use Throwable;

/**
 * Writes AttendanceTemplate's grid as .xlsx: the visible `attendance` grid, then the hidden `lists` (the dropdown) and `versions` (G4).
 * Also writes SiteWorkbookTemplate's site workbook (calculations §14 W1).
 */
final class XlsxTemplateWriter
{
    private const GREY = 'D9D9D9';

    /** Site workbook: day-sheet column => [its list's column in `lists`, the $book key]. A the name, B the status, I the residence. */
    private const DROPDOWNS = [0 => [1, 'names'], 1 => [0, 'statuses'], 8 => [2, 'residences']];

    /** Site workbook: the first data row of a day sheet (row 1 the title, row 2 the headers). */
    private const FIRST_ROW = 3;

    /**
     * @param  array{header: non-empty-list<string>, rows: list<array{code: int, name: string, cells: list<?string>, versions: list<?int>, grey: list<int>}>, choices: list<string>}  $grid
     * @return string the path of a temp file; the caller deletes it
     */
    public function write(array $grid, string $locale): string
    {
        $options = new Options;
        $rows = count($grid['rows']);
        if ($rows > 0 && $grid['choices'] !== []) {
            // Day columns C.. of every employee row; CellReference has no whole-column form, so the list range is exact.
            $options->addValidation(2, 2, count($grid['header']) - 1, $rows + 1, new ListValidationRule(new CellReference(0, 1, 0, count($grid['choices']), sheetName: 'lists')));
        }

        return $this->save($options, 'attendance-template-', fn (Writer $writer) => $this->fill($writer, $grid, $locale));
    }

    /**
     * The site workbook, laid out as the owner's approved sample: one right-to-left sheet per day with `rows` empty rows, then the
     * hidden `lists` (A statuses, B names, C residences, D `meta`: the site id, from, to).
     *
     * @param  array{sheets: non-empty-list<array{name: string, title: string}>, columns: non-empty-list<string>, rows: positive-int, statuses: list<string>, names: list<string>, residences: list<string>, meta: list<string>, prompt: array{title: string, text: string}}  $book
     * @return string the path of a temp file; the caller deletes it
     */
    public function writeWorkbook(array $book): string
    {
        $last = self::FIRST_ROW + $book['rows'] - 1;
        $options = new Options;
        foreach (array_keys($book['sheets']) as $i) {
            foreach (self::DROPDOWNS as $column => [$listColumn, $key]) {
                if ($book[$key] === []) {
                    continue;   // a list range of no rows would make the file invalid
                }
                // A has no error alert, so Excel keeps a typed name outside the list (a new day laborer); B and I are blocking.
                $display = $column === 0
                    ? new ValidationDisplay(showErrorMessage: false, promptTitle: $book['prompt']['title'], prompt: $book['prompt']['text'])
                    : new ValidationDisplay;
                $rule = new ListValidationRule(new CellReference($listColumn, 1, $listColumn, count($book[$key]), sheetName: 'lists'));
                $options->addValidation($column, self::FIRST_ROW, $column, $last, $rule, $display, $i);
            }
            $options->mergeCells(0, 1, count($book['columns']) - 1, 1, $i);
        }

        return $this->save($options, 'site-workbook-', fn (Writer $writer) => $this->fillWorkbook($writer, $book));
    }

    /**
     * Opens a temp file, fills it and closes it; on failure removes the file and OpenSpout's working folder.
     *
     * @param  Closure(Writer): void  $fill
     */
    private function save(Options $options, string $prefix, Closure $fill): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new RuntimeException('Cannot create a temp file for the Excel file.');
        }
        $writer = new Writer($options);
        try {
            $writer->openToFile($path);
            $fill($writer);
            $writer->close();
        } catch (Throwable $e) {
            try {
                $writer->close();   // also removes OpenSpout's working folder
            } catch (Throwable) {
                // The first error is the one to report.
            }
            @unlink($path);

            throw $e;
        }

        return $path;
    }

    /** @param  array{header: non-empty-list<string>, rows: list<array{code: int, name: string, cells: list<?string>, versions: list<?int>, grey: list<int>}>, choices: list<string>}  $grid */
    private function fill(Writer $writer, array $grid, string $locale): void
    {
        $text = new Style(format: '@');
        $grey = new Style(backgroundColor: self::GREY, format: '@');

        $sheet = $writer->getCurrentSheet()->setName('attendance')->setSheetView(new SheetView(rightToLeft: $locale === 'ar', freezeRow: 2, freezeColumn: 'C'));
        // Widths in characters, plus 2 of padding: code, name (12..40), and every day column fits a date or the longest dropdown choice.
        $fit = fn (array $values) => max(0, ...array_map(fn (string $v) => mb_strlen($v), $values)) + 2;
        $sheet->setColumnWidth($fit([$grid['header'][0], ...array_map(fn (array $r) => (string) $r['code'], $grid['rows'])]), 1);
        $sheet->setColumnWidth(min(max($fit([$grid['header'][1], ...array_column($grid['rows'], 'name')]), 12), 40), 2);
        $sheet->setColumnWidthForRange($fit([...array_slice($grid['header'], 2), ...$grid['choices']]), 3, max(3, count($grid['header'])));
        $writer->addRow(self::row($grid['header'], new Style(fontBold: true, format: '@')));
        foreach ($grid['rows'] as $r) {
            $cells = [self::cell((string) $r['code'], $text), self::cell($r['name'], $text)];
            $greyDays = array_flip($r['grey']);
            foreach ($r['cells'] as $i => $value) {
                $cells[] = self::cell($value, isset($greyDays[$i]) ? $grey : $text);
            }
            $writer->addRow(new Row($cells));
        }

        $writer->addNewSheetAndMakeItCurrent()->setName('lists')->setIsVisible(false);
        foreach ($grid['choices'] as $choice) {
            $writer->addRow(self::row([$choice]));
        }

        $writer->addNewSheetAndMakeItCurrent()->setName('versions')->setIsVisible(false);
        $writer->addRow(self::row($grid['header']));
        foreach ($grid['rows'] as $r) {
            $versions = array_map(fn (?int $v) => $v === null ? new EmptyCell(null) : new NumericCell($v), $r['versions']);
            $writer->addRow(new Row([self::cell((string) $r['code']), self::cell($r['name']), ...$versions]));
        }
    }

    /** @param  array{sheets: non-empty-list<array{name: string, title: string}>, columns: non-empty-list<string>, rows: positive-int, statuses: list<string>, names: list<string>, residences: list<string>, meta: list<string>, prompt: array{title: string, text: string}}  $book */
    private function fillWorkbook(Writer $writer, array $book): void
    {
        $text = new Style(format: '@');
        $name = new Style(backgroundColor: 'FFF2CC', format: '@');      // A: pick or type the name
        $laborer = new Style(backgroundColor: 'FFF9E5', format: '@');   // G..J: a new day laborer only
        $title = new Style(fontBold: true, fontSize: 14, backgroundColor: 'DDEBF7', format: '@');
        $bold = new Style(fontBold: true, format: '@');
        $columns = array_keys($book['columns']);
        // Widths in characters, plus 3 (Arabic glyphs run wider than the '0' Excel measures by); the title is not measured.
        $widths = array_map(fn (int $c) => max(8, ...array_map(mb_strlen(...), [$book['columns'][$c], ...(isset(self::DROPDOWNS[$c]) ? $book[self::DROPDOWNS[$c][1]] : [])])) + 3, $columns);

        foreach ($book['sheets'] as $i => $day) {
            $sheet = $i === 0 ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName($day['name'])->setSheetView(new SheetView(rightToLeft: true, tabSelected: $i === 0, freezeRow: self::FIRST_ROW, freezeColumn: 'B'));
            foreach ($widths as $c => $width) {
                $sheet->setColumnWidth($width, $c + 1);
            }
            $writer->addRow(new Row(array_map(fn (int $c) => self::cell($c === 0 ? $day['title'] : null, $title), $columns)));
            $writer->addRow(self::row($book['columns'], $bold));
            for ($r = 0; $r < $book['rows']; $r++) {
                // A holds '' rather than nothing: OpenSpout drops a row whose cells are all empty, fills and all.
                $writer->addRow(new Row(array_map(fn (int $c) => match (true) {
                    $c === 0 => new StringCell('', $name),
                    $c >= 6 => new EmptyCell(null, $laborer),
                    default => new EmptyCell(null, $text),
                }, $columns)));
            }
        }

        $writer->addNewSheetAndMakeItCurrent()->setName('lists')->setIsVisible(false);
        $lists = [$book['statuses'], $book['names'], $book['residences'], $book['meta']];
        for ($r = 0; $r < max(array_map(count(...), $lists)); $r++) {
            $writer->addRow(self::row(array_map(fn (array $values) => $values[$r] ?? null, $lists)));
        }
    }

    /** @param  list<?string>  $values */
    private static function row(array $values, ?Style $style = null): Row
    {
        return new Row(array_map(fn (?string $v) => self::cell($v, $style), $values));
    }

    /** Always a string cell: Cell::fromValue would turn a name starting with "=" into a formula. */
    private static function cell(?string $value, ?Style $style = null): Cell
    {
        return $value === null || $value === '' ? new EmptyCell(null, $style) : new StringCell($value, $style);
    }
}
