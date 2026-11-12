<?php

namespace Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
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
