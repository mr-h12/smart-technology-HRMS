<?php

namespace Tests\Unit;

use App\Modules\Employees\Infrastructure\XlsxSheetRows;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
}
