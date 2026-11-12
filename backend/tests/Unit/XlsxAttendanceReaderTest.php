<?php

namespace Tests\Unit;

use App\Modules\Attendance\Domain\AttendanceRefusal;
use App\Modules\Attendance\Domain\AttendanceRefused;
use App\Modules\Attendance\Infrastructure\XlsxAttendanceReader;
use DateInterval;
use DateTimeImmutable;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateIntervalCell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\TestCase;

/** The site workbook's reading of an upload (W2, W8): sheets with their visibility, the row cap, and times as Excel stores them. */
class XlsxAttendanceReaderTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function test_sheets_returns_the_visible_sheets_and_lists_with_their_visibility(): void
    {
        $path = $this->xlsx([
            ['A', true, [['title'], ['head'], ['x', 'y']]],
            ['other', false, [['skipped']]],
            ['lists', false, [['l1'], ['l2'], ['l3'], ['l4'], ['l5']]],
        ]);

        $this->assertSame([
            ['name' => 'A', 'visible' => true, 'rows' => [1 => ['title'], 2 => ['head'], 3 => ['x', 'y']]],
            ['name' => 'lists', 'visible' => false, 'rows' => [1 => ['l1'], 2 => ['l2'], 3 => ['l3']]],
        ], (new XlsxAttendanceReader)->sheets($path, 2000, []));
    }

    public function test_a_visible_lists_is_still_read_as_lists_to_row_3(): void
    {
        $path = $this->xlsx([['A', true, [['title']]], ['lists', true, [['l1'], ['l2'], ['l3'], ['l4'], ['l5']]]]);

        $this->assertSame(
            ['name' => 'lists', 'visible' => true, 'rows' => [1 => ['l1'], 2 => ['l2'], 3 => ['l3']]],
            (new XlsxAttendanceReader)->sheets($path, 2, [])[1],
        );
    }

    public function test_lists_reads_the_range_dates_alike(): void
    {
        $date = new NumericCell(46333);   // 2026-11-07 retyped as a General number
        $path = $this->xlsx([['A', true, [['title']]], ['lists', false, [['x', '', '', 'site'], ['', '', '', $date], ['', '', '', $date]]]]);

        $this->assertSame([2 => [null, null, null, '2026-11-07'], 3 => [null, null, null, '2026-11-07']], array_slice((new XlsxAttendanceReader)->sheets($path, 2000, [])[1]['rows'], 1, null, true));
    }

    public function test_time_columns_read_what_excel_makes_of_a_typed_time(): void
    {
        $negative = new DateInterval('PT8H5M');
        $negative->invert = 1;
        $rows = [['title'], ['head']];
        foreach ([
            new NumericCell(0.375),
            new DateTimeCell(new DateTimeImmutable('2026-11-07 09:30'), new Style(format: 'h:mm')),
            new DateIntervalCell(new DateInterval('PT8H5M'), new Style(format: '[h]:mm')),
            '٩:٠٥',
            new DateIntervalCell($negative, new Style(format: '[h]:mm')),
        ] as $time) {
            $rows[] = ['name', 'حاضر', $time, '', new NumericCell(0.5)];
        }
        $path = $this->xlsx([['A', true, $rows], ['lists', false, [['x']]]]);

        $read = (new XlsxAttendanceReader)->sheets($path, 2000, [2, 3])[0]['rows'];

        // A negative duration is not a time: it reads as before, and the import refuses it.
        $this->assertSame(['09:00', '09:30', '08:05', '9:05', '0 08:05:00'], array_map(fn (array $cells) => $cells[2], array_slice($read, 2)));
        $this->assertSame(['0.5', '0.5', '0.5', '0.5', '0.5'], array_map(fn (array $cells) => $cells[4], array_slice($read, 2)));   // W8: only the named columns are times
    }

    public function test_the_row_cap_counts_rows_below_the_two_head_rows(): void
    {
        $lists = ['lists', false, [['l1'], ['l2'], ['l3'], ['l4'], ['l5']]];
        $reader = new XlsxAttendanceReader;

        $two = $this->xlsx([['A', true, [['title'], ['head'], ['r3'], ['r4']]], $lists]);
        $this->assertCount(2, $reader->sheets($two, 2, []));

        $three = $this->xlsx([['A', true, [['title'], ['head'], ['r3'], ['r4'], ['r5']]], $lists]);
        try {
            $reader->sheets($three, 2, []);
            $this->fail('three data rows passed a cap of two');
        } catch (AttendanceRefused $e) {
            $this->assertSame(AttendanceRefusal::TooManyRows, $e->reason);
            $this->assertSame(['max' => '2'], $e->params);
        }
    }

    /** @param list<array{string, bool, list<list<Cell|string>>}> $sheets name, visible, rows from row 1 */
    private function xlsx(array $sheets): string
    {
        $path = $this->paths[] = (string) tempnam(sys_get_temp_dir(), 'reader-test-');
        $writer = new Writer;
        $writer->openToFile($path);
        foreach ($sheets as $i => [$name, $visible, $rows]) {
            $sheet = $i === 0 ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName($name);
            $sheet->setIsVisible($visible);
            foreach ($rows as $cells) {
                $writer->addRow(new Row(array_map(fn (Cell|string $c) => $c instanceof Cell ? $c : Cell::fromValue($c), $cells)));
            }
        }
        $writer->close();

        return $path;
    }
}
