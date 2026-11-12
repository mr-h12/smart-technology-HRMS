<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application;

use App\Modules\Attendance\Domain\Employment;
use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Sites\Application\SiteService;
use DateTimeImmutable;

/** The site workbook (ملف الموقع, calculations §14 W1–W4): one site's empty Excel file for a range of days, filled by its site manager. */
final class SiteWorkbookTemplate
{
    public function __construct(
        private readonly EmployeeRoster $roster,
        private readonly SiteService $sites,
    ) {}

    /**
     * The name list (W4): `name_ar (type label) · code`, the site's own people first. The label is the type on the first day of the
     * range the person is employed, in Arabic whatever the request language (W1).
     *
     * @return list<array{entry: string, employee: array<string, mixed>, own: bool}>
     */
    public function names(string $siteId, string $from, string $to): array
    {
        /** @var list<array{id: string, name: string}> $managers */
        $managers = $this->sites->show($siteId)['managers'];
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
