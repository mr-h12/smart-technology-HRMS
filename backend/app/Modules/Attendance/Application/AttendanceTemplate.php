<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application;

use App\Modules\Attendance\Infrastructure\XlsxTemplateWriter;
use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Sites\Infrastructure\Eloquent\Site;

/** The Excel grid template of one group's period (slice 3): one row per employee, one column per period day, each cell a site or a leave type. */
final class AttendanceTemplate
{
    public function __construct(
        private readonly AttendanceGrid $periods,
        private readonly EmployeeRoster $roster,
        private readonly EffectiveDays $days,
        private readonly XlsxTemplateWriter $writer,
    ) {}

    /** @return string the path of a temp .xlsx file; the caller deletes it */
    public function file(EmployeeType $g, string $start, ?string $ownerId): string
    {
        $grid = $this->grid($g, $start, $ownerId);

        return $this->writer->write([...$grid, 'header' => self::header($grid['dates'])], app()->getLocale());
    }

    /**
     * Row 1 of the attendance and versions sheets (G7), in $locale (default: the request language); the import checks an upload against it.
     *
     * @param  list<string>  $dates
     * @return non-empty-list<string>
     */
    public static function header(array $dates, ?string $locale = null): array
    {
        return [(string) __('attendance.template.code', [], $locale), (string) __('attendance.template.name', [], $locale), ...$dates];
    }

    /**
     * $ownerId (own scope) narrows the rows to that manager's employees, as the sheet does. A grey cell (G5: not employed as
     * the group's type, or a future day) is always blank. A stored office day without a site is a present day (G6).
     *
     * @return array{dates: list<string>, rows: list<array{employee_id: string, code: int, name: string, cells: list<?string>, versions: list<?int>, grey: list<int>}>, choices: list<string>}
     */
    public function grid(EmployeeType $g, string $start, ?string $ownerId): array
    {
        $p = $this->periods->periodAt($g, $start);
        $employees = $this->roster->allOfType($g, $p->start, $p->end, $ownerId);
        $days = $this->days->forPeriod($p, $employees);
        // A stored day may point at a since-deactivated or deleted site; it still shows by name.
        $sites = Site::withTrashed()->orderBy('name_ar')->get(['id', 'name_ar', 'kind', 'active', 'deleted_at']);
        $names = $sites->pluck('name_ar', 'id')->all();
        $present = (string) __('attendance.template.present');
        $today = EffectiveDays::today();

        $rows = [];
        foreach ($employees as $e) {
            $row = ['employee_id' => (string) $e['id'], 'code' => (int) $e['code'], 'name' => (string) $e['name_ar'], 'cells' => [], 'versions' => [], 'grey' => []];
            foreach (array_values($days[$e['id']]) as $i => $d) {
                $grey = $d['state'] === 'not_employed' || $d['date'] > $today;
                if ($grey) {
                    $row['grey'][] = $i;
                }
                $row['cells'][] = match (true) {
                    $grey => null,
                    $d['site_id'] !== null => $names[$d['site_id']] ?? null,
                    $d['state'] === 'stored' => $present,
                    default => null,
                };
                $row['versions'][] = $d['version'];
            }
            $rows[] = $row;
        }

        $live = $sites->filter(fn (Site $s) => $s->active && ! $s->trashed());
        $first = $g === EmployeeType::Office ? [$present] : $live->where('kind', 'work')->pluck('name_ar')->all();

        return ['dates' => $p->days(), 'rows' => $rows, 'choices' => array_values([...$first, ...$live->where('kind', 'leave')->pluck('name_ar')->all()])];
    }
}
