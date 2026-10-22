<?php

namespace Tests\Unit;

use App\Modules\Employees\Infrastructure\XlsxSheetRows;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class XlsxSheetRowsTest extends TestCase
{
    #[Test]
    public function reads_sheets_by_header(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'sheet-rows-');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('sites');
        foreach ([['name_ar', 'allowance_value'], [' موقع أ ', '12.5'], ['', ''], ['موقع ب', '']] as $values) {
            $writer->addRow(Row::fromValues($values));
        }
        $writer->close();

        try {
            $rows = (new XlsxSheetRows)->read($path)['sites'];
        } finally {
            @unlink($path);
        }

        $this->assertCount(2, $rows);
        $this->assertSame([2, 4], array_column($rows, 'row'));
        $this->assertSame('موقع أ', $rows[0]['cells']['name_ar']);
        $this->assertSame('12.5', $rows[0]['cells']['allowance_value']);
        $this->assertSame('موقع ب', $rows[1]['cells']['name_ar']);
        $this->assertNull($rows[1]['cells']['allowance_value']);
    }

    #[Test]
    public function flags_cells_whose_value_is_unknown_or_numeric_and_data_without_a_header(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'sheet-rows-');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('employees');
        $writer->addRow(Row::fromValues(['error', 'uncached', 'cached_error', 'cached_number', 'number', 'text', '', 'blank']));
        $writer->addRow(new Row([
            new Cell\ErrorCell('#N/A'),
            new Cell\FormulaCell('=UNCACHED()'),
            new Cell\FormulaCell('=ERRF()'),
            new Cell\FormulaCell('=OKF()'),
            Cell::fromValue(1012345678),
            Cell::fromValue('01012345678'),
            Cell::fromValue('no header'),
        ]));
        $writer->addRow(new Row([new Cell\ErrorCell('#REF!')]));   // a row holding only an error is not blank
        $writer->close();
        // OpenSpout writes formulas without the value Excel caches on save; add the cache Excel would have written.
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $xml = (string) preg_replace('/<c ([^>]*)><f>ERRF\(\)<\/f><\/c>/', '<c $1 t="e"><f>ERRF()</f><v>#N/A</v></c>', $xml);
        $xml = (string) preg_replace('/<c ([^>]*)><f>OKF\(\)<\/f><\/c>/', '<c $1><f>OKF()</f><v>5</v></c>', $xml);
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();

        try {
            $rows = (new XlsxSheetRows)->read($path)['employees'];
        } finally {
            @unlink($path);
        }

        $this->assertCount(2, $rows);
        [$first, $second] = $rows;
        $this->assertSame(['error', 'uncached', 'cached_error'], $first['unreadable']);
        $this->assertSame('5', $first['cells']['cached_number']);
        $this->assertSame('1012345678', $first['cells']['number']);
        $this->assertSame(['cached_number', 'number'], $first['numeric']);
        $this->assertSame([7], $first['unlabelled']);   // 1-based column number
        $this->assertSame(['error'], $second['unreadable']);
    }
}
