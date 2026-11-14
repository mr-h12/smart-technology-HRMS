<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application;

use App\Modules\Attendance\Domain\Employment;
use App\Modules\Attendance\Infrastructure\XlsxTemplateWriter;
use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Sites\Application\SiteService;
use App\Modules\Sites\Domain\LeaveCode;
use App\Modules\Sites\Domain\SiteKind;
use App\Modules\Sites\Infrastructure\Eloquent\Residence;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use DateTimeImmutable;
use InvalidArgumentException;

/** The site workbook (ملف الموقع, calculations §14 W1–W4): one site's empty Excel file for a range of days, filled by its site manager. */
final class SiteWorkbookTemplate
{
    /** W1: one sheet per day, at most this many (a technical limit of the file, not a setting). */
    public const MAX_DAYS = 31;

    /**
     * The years a range may fall in: the window the leave and holiday endpoints already take (a technical limit, not a setting). The
     * attendance code compares and walks Y-m-d strings, which holds only while every date, and the periods around it, has four digits.
     */
    public const YEARS = [2000, 2100];

    /** Empty rows per day sheet, or twice the site's own people when that is more (a technical limit of the file, not a setting). */
    public const MIN_ROWS = 30;

    public function __construct(
        private readonly EmployeeRoster $roster,
        private readonly SiteService $sites,
        private readonly XlsxTemplateWriter $writer,
    ) {}

    /**
     * W1: the empty file, in Arabic whatever the request language. $siteId is a live, active work site and $from..$to a range dates()
     * accepts; the caller checks both.
     *
     * @return string the path of a temp .xlsx file; the caller deletes it
     */
    public function file(string $siteId, string $from, string $to): string
    {
        $dates = self::dates($from, $to) ?? throw new InvalidArgumentException("{$from}..{$to} is no site workbook range.");
        /** @var array{name_ar: string, managers: list<array{id: string, name: string}>} $site */
        $site = $this->sites->show($siteId);
        $names = $this->names($siteId, $from, $to, $site['managers']);
        // As the approved sample: absence (غ) first, then the other leave day-types by name; `is distinct from` is never null.
        /** @var list<string> $leave */
        $leave = Site::where(['kind' => SiteKind::Leave->value, 'active' => true])
            ->orderByRaw('leave_code is distinct from ?', [LeaveCode::Absent->value])->orderBy('name_ar')->pluck('name_ar')->all();
        /** @var list<string> $residences */
        $residences = Residence::orderBy('name_ar')->pluck('name_ar')->all();

        return $this->writer->writeWorkbook([
            'sheets' => array_map(fn (string $d) => [
                'name' => self::sheetName($d),
                'title' => (string) __('attendance.workbook.title', ['site' => $site['name_ar'], 'day' => self::sheetName($d).'-'.substr($d, 0, 4)], 'ar'),
            ], $dates),
            'columns' => self::columns(),
            'rows' => max(self::MIN_ROWS, 2 * count(array_filter($names, fn (array $n) => $n['own']))),
            'statuses' => [(string) __('attendance.template.present', [], 'ar'), ...$leave],
            'names' => array_column($names, 'entry'),
            'residences' => $residences,
            'meta' => [$siteId, $from, $to],
            'periods' => self::periods(),
            'prompt' => ['title' => (string) __('attendance.workbook.prompt_title', [], 'ar'), 'text' => (string) __('attendance.workbook.prompt', [], 'ar')],
        ]);
    }

    /** @return non-empty-list<string> the twelve headers of a day sheet: the owner's rule 4, with a ص/م after each time (2026-11-14) */
    public static function columns(): array
    {
        /** @var non-empty-list<string> $columns */
        $columns = __('attendance.workbook.columns', [], 'ar');

        return $columns;
    }

    /** @return array{string, string} W8: ص (morning) and م (evening), the choices of the ص/م column beside each time */
    public static function periods(): array
    {
        /** @var array{string, string} $periods */
        $periods = __('attendance.workbook.periods', [], 'ar');

        return $periods;
    }

    /** A day sheet's name: the Arabic weekday and `dd-mm`, e.g. «السبت 07-11». */
    public static function sheetName(string $date): string
    {
        $d = new DateTimeImmutable($date);

        return __('attendance.workbook.weekday.'.$d->format('w'), [], 'ar').' '.$d->format('d-m');
    }

    /**
     * The name list (W4): `name_ar (type label) · code`, the site's own people first. The label is the type on the first day of the
     * range the person is employed, in Arabic whatever the request language (W1).
     *
     * @param  list<array{id: string, name: string}>|null  $managers  the site's managers (SiteService::show), when the caller has read them
     * @return list<array{entry: string, employee: array<string, mixed>, own: bool}>
     */
    public function names(string $siteId, string $from, string $to, ?array $managers = null): array
    {
        /** @var list<array{id: string, name: string}> $managers */
        $managers = $managers ?? $this->sites->show($siteId)['managers'];
        $dates = self::dates($from, $to) ?? throw new InvalidArgumentException("{$from}..{$to} is no site workbook range.");
        $people = $this->roster->siteWorkbookPeople($siteId, array_column($managers, 'id'), $from, $to);

        $names = [];
        foreach (['own' => true, 'others' => false] as $group => $own) {
            foreach ($people[$group] as $e) {
                $type = null;
                foreach ($dates as $d) {
                    if (($type = Employment::typeOn($e, $d)) !== null) {
                        break;
                    }
                }
                if ($type === null) {
                    continue; // only an office row can be employed on no day of the range (officeIdsAt reads the type, not the employment)
                }
                $label = (string) __('attendance.workbook.type.'.$type->value, [], 'ar');
                $names[] = ['entry' => "{$e['name_ar']} ({$label}) · {$e['code']}", 'employee' => $e, 'own' => $own];
            }
        }

        return $names;
    }

    /**
     * W1, W2, the one range check of the download and the upload: every date from $from to $to (Y-m-d), in order; null unless both
     * are real dates (2026-02-30 is not) within YEARS, $from is not after $to and the range is at most MAX_DAYS dates. The length
     * comes from the two dates, so no list is built for a range that is too long.
     *
     * @return non-empty-list<string>|null
     */
    public static function dates(?string $from, ?string $to): ?array
    {
        [$first, $last] = [self::date($from), self::date($to)];
        if ($first === null || $last === null || $first > $last) {
            return null;
        }
        $days = (int) $first->diff($last)->days + 1;

        return $days > self::MAX_DAYS ? null : array_map(fn (int $i) => $first->modify("+{$i} days")->format('Y-m-d'), range(0, $days - 1));
    }

    /** A real Y-m-d date within YEARS, else null. */
    private static function date(?string $value): ?DateTimeImmutable
    {
        $date = $value === null ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $year = $date === false ? 0 : (int) $date->format('Y');

        return $date !== false && $date->format('Y-m-d') === $value && $year >= self::YEARS[0] && $year <= self::YEARS[1] ? $date : null;
    }
}
