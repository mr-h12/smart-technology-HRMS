<?php

namespace Tests;

use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/** A downloaded .xlsx read back: its bytes, its sheets through OpenSpout, and its zip entries for the XML checks. Use in a TestCase. */
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
}
