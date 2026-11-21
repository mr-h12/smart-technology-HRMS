<?php

namespace Tests\Unit;

use App\Modules\Attendance\Domain\AttendanceRefusal;
use App\Modules\Attendance\Domain\AttendanceRefused;
use App\Modules\Attendance\Domain\Punch;
use App\Modules\Attendance\Domain\PunchRowError;
use App\Modules\Attendance\Infrastructure\XlsxPunchFileReader;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use Tests\TestCase;
use Tests\XlsxFiles;

/** The fingerprint device's export read into punches (fingerprint design spec, "The file", "Test workbook"; plan FP-1). */
class XlsxPunchFileReaderTest extends TestCase
{
    use XlsxFiles;

    /** The spec's "Test workbook" table, in file order: 13 of the 14 time cells after de-duplication. */
    private const PUNCHES = [
        [7, 'SaraNabil', '2026-09-27', '08:55:10'], [7, 'SaraNabil', '2026-09-27', '17:02:30'],
        [11, 'HadiSalem', '2026-09-27', '08:59:01'], [11, 'HadiSalem', '2026-09-27', '08:59:03'], [11, 'HadiSalem', '2026-09-27', '17:30:00'],
        [7, 'SaraNabil', '2026-09-28', '16:45:00'],
        [7, 'SaraNabil', '2026-09-29', '09:20:00'],
        [11, 'HadiSalem', '2026-09-29', '08:50:00'],
        [7, 'SaraNabil', '2026-09-29', '17:10:00'],
        [11, 'HadiSalem', '2026-09-29', '12:00:00'], [11, 'HadiSalem', '2026-09-29', '17:05:00'],
        [12, 'MonaAdel', '2026-09-30', '09:01:00'], [12, 'MonaAdel', '2026-09-30', '17:00:00'],
    ];

    public function test_the_test_workbook_reads_into_the_specs_thirteen_punches(): void
    {
        $read = $this->read($this->fingerprintFile());

        $this->assertSame(self::PUNCHES, $this->punches($read['punches']));
        $this->assertSame([], $read['errors']);
    }

    public function test_a_hidden_sheet_is_read_like_a_visible_one(): void
    {
        $read = $this->read($this->fingerprintFile(hidden: ['Page 2']));

        $this->assertSame(self::PUNCHES, $this->punches($read['punches']));
    }

    public function test_a_sheet_with_another_header_is_ignored_and_the_header_ignores_case_and_spaces(): void
    {
        $sheets = [...self::FINGERPRINT_SHEETS, 'Summary' => [['2026-09-27', 99, 'Nobody', ['nonsense'], true]]];
        $read = $this->read($this->fingerprintFile($sheets, [
            'Page 2' => [' DATE', 'id number ', 'Name', 'in', 'OUT', 'In', 'Out', 'IN', 'OUT'],
            'Summary' => ['Name', 'Date', 'ID Number', 'IN', 'OUT', 'IN', 'OUT', 'IN', 'OUT', 'More'],
        ]));

        $this->assertSame(self::PUNCHES, $this->punches($read['punches']));
        $this->assertSame([], $read['errors']);
    }

    public function test_a_file_without_a_device_sheet_is_not_a_fingerprint_file(): void
    {
        $path = $this->fingerprintFile(null, [
            'Sheet1' => ['Date', 'ID Number', 'Name', 'IN', 'OUT', 'IN', 'OUT', 'IN'],
            'Page 2' => ['Employee', 'Date'],
        ]);

        $this->assertRefused(AttendanceRefusal::NotFingerprintFile, fn () => $this->read($path));
    }

    public function test_each_bad_row_gives_one_error_with_its_position_and_no_punches(): void
    {
        $blank = [new EmptyCell(null), new EmptyCell(null), new EmptyCell(null), [], false];
        $read = $this->read($this->fingerprintFile(['Sheet1' => [
            [new StringCell('soon'), 7, 'SaraNabil', [new StringCell('noon')], true],
            ['2026-09-27', 'x', 'SaraNabil', [new StringCell('noon')], true],
            ['2026-09-27', 0, 'SaraNabil', ['08:00:00'], true],
            $blank,
            ['2026-09-27', 7, '  ', ['08:00:00'], true],
            ['2026-09-27', 7, 'SaraNabil', ['08:00:00', new StringCell('25:00')], true],
            ['2026-10-26', 7, 'SaraNabil', ['08:00:00'], true],
            ['2026-09-28', 7, 'SaraNabil', [], false],
            ['2026-09-29', 7, ' Sara   Nabil ', ['09:00:00', new StringCell('8:05')], true],
            ['2026-10-26', 7, '  ', [new StringCell('25:00')], true],
            ['2026-10-26', 7, 'SaraNabil', [new StringCell('25:00')], true],
        ]]));

        $this->assertSame([
            ['sheet' => 'Sheet1', 'row' => 2, 'code' => PunchRowError::BadDate],
            ['sheet' => 'Sheet1', 'row' => 3, 'code' => PunchRowError::BadDeviceUserId],
            ['sheet' => 'Sheet1', 'row' => 4, 'code' => PunchRowError::BadDeviceUserId],
            ['sheet' => 'Sheet1', 'row' => 6, 'code' => PunchRowError::MissingName],
            ['sheet' => 'Sheet1', 'row' => 7, 'code' => PunchRowError::BadTime],
            ['sheet' => 'Sheet1', 'row' => 8, 'code' => PunchRowError::FutureDate],
            ['sheet' => 'Sheet1', 'row' => 11, 'code' => PunchRowError::MissingName],
            ['sheet' => 'Sheet1', 'row' => 12, 'code' => PunchRowError::BadTime],
        ], $read['errors'], 'the first code in order; the blank row 5 and the row with no time give no error');
        $this->assertSame([[7, 'Sara   Nabil', '2026-09-29', '09:00:00'], [7, 'Sara   Nabil', '2026-09-29', '08:05:00']], $this->punches($read['punches']),
            'a text time 8:05 reads as 08:05:00');
    }

    public function test_the_name_reaches_the_caller_as_the_file_spells_it(): void
    {
        $read = $this->read($this->fingerprintFile(['Sheet1' => [
            ['2026-09-27', 1, "Sara\u{00A0}Nabil", ['08:00:00'], true],
            ['2026-09-27', 2, "Ali\u{0663}", ['08:00:00'], true],
            ['2026-09-27', 3, 'Sara  Nabil', ['08:00:00'], true],
            ['2026-09-27', 4, " \t Sara Nabil \n", ['08:00:00'], true],
            ['2026-09-27', 5, "Sara\x00Nabil", ['08:00:00'], true],
            ['2026-09-27', 6, 1234, ['08:00:00'], true],
        ]]));

        $this->assertSame(["Sara\u{00A0}Nabil", "Ali\u{0663}", 'Sara  Nabil', 'Sara Nabil', 'Sara Nabil', '1234'],
            array_map(fn (Punch $p) => $p->deviceName, $read['punches']),
            'only outer ASCII whitespace is trimmed and a control character reads as a space; matching ignores the rest in FP-2');
    }

    public function test_a_duplicate_punch_keeps_the_first_rows_spelling(): void
    {
        $read = $this->read($this->fingerprintFile(['Sheet1' => [['2026-09-27', 7, 'SaraNabil', ['08:00:00'], true]],
            'Page 2' => [['2026-09-27', 7, 'saranabil', ['08:00:00'], true]]]));

        $this->assertSame([[7, 'SaraNabil', '2026-09-27', '08:00:00']], $this->punches($read['punches']));
    }

    public function test_rows_dated_after_today_are_future_date(): void
    {
        $read = $this->read($this->fingerprintFile(), today: '2026-09-28');

        $this->assertSame([
            ['sheet' => 'Sheet1', 'row' => 5, 'code' => PunchRowError::FutureDate],
            ['sheet' => 'Sheet1', 'row' => 6, 'code' => PunchRowError::FutureDate],
            ['sheet' => 'Page 2', 'row' => 2, 'code' => PunchRowError::FutureDate],
            ['sheet' => 'Page 2', 'row' => 3, 'code' => PunchRowError::FutureDate],
            ['sheet' => 'Page 2', 'row' => 4, 'code' => PunchRowError::FutureDate],
        ], $read['errors']);
        $this->assertSame(array_slice(self::PUNCHES, 0, 6), $this->punches($read['punches']));
    }

    public function test_data_rows_count_across_sheets_against_the_cap(): void
    {
        $path = $this->fingerprintFile();

        $this->assertCount(13, $this->read($path, 8)['punches']);
        $this->assertRefused(AttendanceRefusal::TooManyRows, fn () => $this->read($path, 7));
    }

    public function test_a_file_that_is_not_xlsx_is_unreadable(): void
    {
        $path = $this->temp();
        file_put_contents($path, 'not a workbook');

        $this->assertRefused(AttendanceRefusal::UnreadableFile, fn () => $this->read($path));
    }

    /** @return array{punches: list<Punch>, errors: list<array{sheet: string, row: int, code: PunchRowError}>} */
    private function read(string $path, int $maxRows = 5000, string $today = '2026-10-25'): array
    {
        return new XlsxPunchFileReader()->read($path, $maxRows, $today);
    }

    /**
     * @param  list<Punch>  $punches
     * @return list<array{int, string, string, string}>
     */
    private function punches(array $punches): array
    {
        return array_map(fn (Punch $p) => [$p->deviceUserId, $p->deviceName, $p->date, $p->time], $punches);
    }

    private function assertRefused(AttendanceRefusal $reason, callable $read): void
    {
        try {
            $read();
            $this->fail("expected {$reason->value}");
        } catch (AttendanceRefused $e) {
            $this->assertSame($reason, $e->reason);
        }
    }
}
