<?php

namespace Tests\Feature;

use DateTimeImmutable;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;
use Tests\XlsxFiles;

/** The test workbook FP-1 and FP-4 build on is laid out as the device exports (fingerprint design spec, "Test workbook"). */
class FingerprintFileTest extends TestCase
{
    use XlsxFiles;

    public function test_the_builder_writes_the_device_layout(): void
    {
        $path = $this->fingerprintFile();
        $reader = new Reader;
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }
        $reader->close();

        $this->assertSame(['Sheet1', 'Page 2'], array_keys($sheets));
        $punches = [];
        foreach ($sheets as $name => $rows) {
            $this->assertSame(self::FINGERPRINT_HEADER, $rows[0], $name);
            foreach (array_slice($rows, 1) as $i => $cells) {
                $this->assertInstanceOf(DateTimeImmutable::class, $cells[0], 'A is a date serial with a date format');
                $this->assertIsInt($cells[1]);
                $this->assertIsString($cells[2]);
                $times = array_values(array_filter(array_slice($cells, 3, 6), fn ($v) => $v !== null && $v !== ''));
                foreach ($times as $t) {
                    $this->assertInstanceOf(DateTimeImmutable::class, $t, 'D–I are day fractions with a time format');
                }
                $key = $cells[1].' '.$cells[0]->format('Y-m-d');
                $punches[$key][] = array_map(fn (DateTimeImmutable $t) => $t->format('H:i:s'), $times);
                $more[$name][$i + 2] = $cells[9] ?? null;
            }
        }

        $this->assertSame([['08:59:01', '08:59:03', '17:30:00']], $punches['11 2026-09-27'], 'a double tap');
        $this->assertSame([['16:45:00']], $punches['7 2026-09-28'], 'a one-punch day');
        $this->assertSame('More', $more['Page 2'][2]);
        [$earlier, $later] = $punches['7 2026-09-29'];
        $this->assertSame($earlier, array_slice($later, 0, count($earlier)), 'an overlap whose earlier row is a prefix');
        [$a, $b] = $punches['11 2026-09-29'];
        $this->assertNotSame([], array_diff($a, $b));
        $this->assertNotSame([], array_diff($b, $a), 'an overlap where each row has a punch the other lacks');

        $this->xlsx = (string) file_get_contents($path);
        $this->assertStringContainsString('numFmtId="14"', $this->zipEntry('xl/styles.xml'));
        $this->assertStringContainsString('numFmtId="21"', $this->zipEntry('xl/styles.xml'));
        $this->assertStringContainsString('http://192.0.2.10/', $this->zipEntry('xl/worksheets/_rels/sheet1.xml.rels'));
        $sheet1 = $this->zipEntry('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('r="J3"', $sheet1);
        $this->assertStringNotContainsString('r="J4"', $sheet1, 'the one-punch row has no "More" cell');
    }
}
