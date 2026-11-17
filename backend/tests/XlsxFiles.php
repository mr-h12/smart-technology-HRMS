<?php

namespace Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * A downloaded .xlsx read back: its bytes, its sheets through OpenSpout, and its zip entries for the XML checks; and a file edited as
 * Excel would save it back, for an upload. Use in a Laravel TestCase.
 */
trait XlsxFiles
{
    /**
     * The fingerprint device's export in miniature (fingerprint design spec, "The file" and "Test workbook"). Two sheets overlap on
     * 2026-09-29: SaraNabil's earlier row is a prefix of her later one, HadiSalem's two rows each hold a punch the other lacks. Also a
     * double tap (HadiSalem, 09-27), a one-punch day under IN (SaraNabil, 09-28, on a row with no "More" cell) and a third name, MonaAdel.
     * A row is [date Y-m-d, device user id, fingerprint name, times H:i:s, has a "More" cell].
     */
    public const FINGERPRINT_SHEETS = [
        'Sheet1' => [
            ['2026-09-27', 7, 'SaraNabil', ['08:55:10', '17:02:30'], true],
            ['2026-09-27', 11, 'HadiSalem', ['08:59:01', '08:59:03', '17:30:00'], true],
            ['2026-09-28', 7, 'SaraNabil', ['16:45:00'], false],
            ['2026-09-29', 7, 'SaraNabil', ['09:20:00'], true],
            ['2026-09-29', 11, 'HadiSalem', ['08:50:00'], true],
        ],
        'Page 2' => [
            ['2026-09-29', 7, 'SaraNabil', ['09:20:00', '17:10:00'], true],
            ['2026-09-29', 11, 'HadiSalem', ['12:00:00', '17:05:00'], true],
            ['2026-09-30', 12, 'MonaAdel', ['09:01:00', '17:00:00'], true],
        ],
    ];

    public const FINGERPRINT_HEADER = ['Date', 'ID Number', 'Name', 'IN', 'OUT', 'IN', 'OUT', 'IN', 'OUT', 'More'];

    /**
     * Writes FINGERPRINT_SHEETS (or $sheets in its shape) as the device does: A an Excel date serial (format 14), B a number, C text,
     * D–I day fractions (format 21), J the text "More" linking to a LAN page (a documentation address). A Cell in a row passes through,
     * for a malformed cell. Returns the temp path.
     *
     * @param  array<string, list<array{0: string|Cell, 1: int|string|Cell, 2: string|Cell, 3: list<string|Cell>, 4: bool}>>|null  $sheets
     */
    protected function fingerprintFile(?array $sheets = null): string
    {
        $date = new Style()->withFormat('mm-dd-yy');
        $time = new Style()->withFormat('h:mm:ss');
        $cell = fn (mixed $v): Cell => $v instanceof Cell ? $v : Cell::fromValue($v);
        $path = $this->temp();
        $writer = new Writer;
        $writer->openToFile($path);
        $first = true;
        foreach ($sheets ?? self::FINGERPRINT_SHEETS as $name => $rows) {
            ($first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent())->setName($name);
            $first = false;
            $writer->addRow(Row::fromValues(self::FINGERPRINT_HEADER));
            foreach ($rows as [$day, $id, $who, $times, $more]) {
                $cells = [
                    is_string($day) ? new NumericCell((new \DateTimeImmutable('1899-12-30'))->diff(new \DateTimeImmutable($day))->days, $date) : $day,
                    $cell($id),
                    $cell($who),
                ];
                foreach (array_pad($times, 6, null) as $t) {
                    $cells[] = match (true) {
                        $t === null => new EmptyCell(null),
                        is_string($t) => new NumericCell((strtotime("1970-01-01 {$t} UTC") ?: 0) / 86400, $time),
                        default => $t,
                    };
                }
                if ($more) {
                    $cells[] = new StringCell('More')->withHyperlink('http://192.0.2.10/punch?id='.(is_int($id) ? $id : 0));
                }
                $writer->addRow(new Row($cells));
            }
        }
        $writer->close();

        return $path;
    }

    /** The downloaded file's bytes, kept for the XML checks. */
    private string $xlsx = '';

    /** Sends a file download (which deletes its temp file) and returns the bytes. */
    protected function sent(TestResponse $response): string
    {
        $file = $response->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $file);
        $path = $file->getFile()->getPathname();
        ob_start();
        $file->sendContent();
        $bytes = (string) ob_get_clean();
        $this->assertFileDoesNotExist($path);

        return $bytes;
    }

    /** @return array<string, array{visible: bool, rows: list<list<mixed>>}> every sheet of the kept file, by name */
    protected function sheets(): array
    {
        $path = $this->tempXlsx();
        $reader = new Reader;
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            $sheets[$sheet->getName()] = ['visible' => $sheet->isVisible(), 'rows' => $rows];
        }
        $reader->close();
        unlink($path);

        return $sheets;
    }

    protected function tempXlsx(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'template-test-');
        file_put_contents($path, $this->xlsx);

        return $path;
    }

    protected function zipEntry(string $name): string
    {
        $path = $this->tempXlsx();
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName($name);
        $zip->close();
        unlink($path);

        return $xml;
    }

    /** A temp file path, removed when the test ends. */
    protected function temp(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'xlsx-test-');
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }

    /** The file at $path as an upload. */
    protected function file(string $path): UploadedFile
    {
        return new UploadedFile($path, 'upload.xlsx', null, null, true);
    }

    /**
     * Rewrites every sheet with OpenSpout's writer, keeping each sheet's visibility. $map(name, rows) returns the new rows, or null to
     * leave the sheet out; $rename gives sheets new names; $order puts these sheets first, in this order. A row value may be a Cell.
     *
     * @param  callable(string, list<list<mixed>>): (list<list<mixed>>|null)  $map
     * @param  array<string, string>  $rename
     * @param  list<string>  $order
     */
    protected function rewrite(string $path, callable $map, array $rename = [], array $order = []): UploadedFile
    {
        $reader = new Reader;
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            $sheets[$sheet->getName()] = [$sheet->isVisible(), $rows];
        }
        $reader->close();

        $out = $this->temp();
        $writer = new Writer;
        $writer->openToFile($out);
        $first = true;
        foreach ([...array_flip($order), ...$sheets] as $name => [$visible, $rows]) {
            $rows = $map($name, $rows);
            if ($rows === null) {
                continue;
            }
            $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $first = false;
            $sheet->setName($rename[$name] ?? $name)->setIsVisible($visible);
            foreach ($rows as $row) {
                $writer->addRow(new Row(array_map(fn (mixed $v): Cell => match (true) {
                    $v instanceof Cell => $v,
                    $v === null || $v === '' => new EmptyCell(null),
                    is_int($v) || is_float($v) => new NumericCell($v),
                    default => new StringCell((string) $v),
                }, array_values($row))));
            }
        }
        $writer->close();

        return $this->file($out);
    }
}
