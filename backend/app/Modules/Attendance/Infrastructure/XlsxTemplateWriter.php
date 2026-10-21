<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure;

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
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use Throwable;

/** Writes AttendanceTemplate's grid as .xlsx: the visible `attendance` grid, then the hidden `lists` (the dropdown) and `versions` (G4). */
final class XlsxTemplateWriter
{
    private const GREY = 'D9D9D9';

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
        $path = tempnam(sys_get_temp_dir(), 'attendance-template-');
        if ($path === false) {
            throw new RuntimeException('Cannot create a temp file for the attendance template.');
        }
        $writer = new Writer($options);
        try {
            $writer->openToFile($path);
            $this->fill($writer, $grid, $locale);
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
