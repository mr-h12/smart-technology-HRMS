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

    /** Empty rows per day sheet, or twice the site's own people when that is more (a technical limit of the file, not a setting). */
    public const MIN_ROWS = 30;

    public function __construct(
        private readonly EmployeeRoster $roster,
        private readonly SiteService $sites,
        private readonly XlsxTemplateWriter $writer,
    ) {}

    /**
     * W1: the empty file, in Arabic whatever the request language. $siteId is a live, active work site and $from..$to at most
     * MAX_DAYS dates; the caller checks both.
     *
     * @return string the path of a temp .xlsx file; the caller deletes it
     */
    public function file(string $siteId, string $from, string $to): string
    {
        $dates = self::dates($from, $to);
        if ($dates === []) {
            throw new InvalidArgumentException("{$from} is after {$to}.");
        }
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
            'prompt' => ['title' => (string) __('attendance.workbook.prompt_title', [], 'ar'), 'text' => (string) __('attendance.workbook.prompt', [], 'ar')],
        ]);
    }

    /** @return non-empty-list<string> the ten headers of a day sheet, in the order of the owner's rule 4 */
    public static function columns(): array
    {
        /** @var non-empty-list<string> $columns */
        $columns = __('attendance.workbook.columns', [], 'ar');

        return $columns;
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
        $people = $this->roster->siteWorkbookPeople($siteId, array_column($managers, 'id'), $from, $to);
        $dates = self::dates($from, $to);

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

    /** @return list<string> every date from $from to $to (Y-m-d), in order */
    public static function dates(string $from, string $to): array
    {
        $dates = [];
        for ($d = new DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
            $dates[] = $d->format('Y-m-d');
        }

        return $dates;
    }
}
