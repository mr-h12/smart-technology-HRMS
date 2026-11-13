<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application;

use App\Modules\Attendance\Domain\AttendanceRefusal;
use App\Modules\Attendance\Domain\AttendanceRefused;
use App\Modules\Attendance\Domain\Period;
use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceDay;
use App\Modules\Attendance\Infrastructure\PeriodGuard;
use App\Modules\Employees\Application\DayCrew;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Identity\Application\Rbac\PermissionHolders;
use App\Modules\Notifications\Application\Notifier;
use App\Modules\Sites\Application\SiteService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * The site manager's changes to today's day-labor crew on one of their sites (owner, 2026-11-04; approved as built 2026-11-05). Only today, Cairo; only day laborers;
 * a laborer on another manager's site that day is HR's to move. Each change holds for today only: past days and
 * tomorrow's roster are unchanged, and removing someone is not an absence.
 */
final class SiteDayCrew
{
    public function __construct(
        private readonly DayCrew $crew,
        private readonly SiteService $sites,
        private readonly Notifier $notifier,
        private readonly PermissionHolders $holders,
        private readonly Signoffs $signoffs,
    ) {}

    /**
     * The one day laborer whose code or full name is $search (DayCrew::find), with where they are today and whether this site
     * manager may add them here; `ambiguous` when two share that name. A site manager never lists the company's laborers.
     *
     * @return array{match: ?array<string, mixed>, ambiguous: bool}
     */
    public function search(string $siteId, string $search, string $managerId): array
    {
        $mine = $this->mySites($siteId, $managerId);
        $found = $this->crew->find($search, EffectiveDays::today(), $managerId);
        $m = $found['match'];
        if ($m === null) {
            return ['match' => null, 'ambiguous' => $found['ambiguous']];
        }
        $at = $m['site_id'];

        return [
            'match' => [
                ...array_diff_key($m, ['site_id' => true]),
                'site' => $at === null ? null : $this->sites->names([$at])[$at] ?? null,
                'here' => $at === $siteId,
                'can_add' => $at !== $siteId && ($at === null || in_array($at, $mine, true)),
            ],
            'ambiguous' => false,
        ];
    }

    public function add(string $siteId, string $employeeId, string $managerId): void
    {
        $mine = $this->mySites($siteId, $managerId);
        $today = EffectiveDays::today();
        // The checks and the change in one transaction with the person locked, as SiteDay::save does (PR #22 review B4).
        $this->crew->locked([$employeeId], function () use ($siteId, $employeeId, $mine, $today, $managerId): void {
            if (! $this->crew->isAddableOn($employeeId, $today)) {
                throw new ModelNotFoundException;
            }
            $current = $this->crew->laborerSiteOn($employeeId, $today);
            if ($current !== null && ! in_array($current, $mine, true)) {
                throw self::refused(AttendanceRefusal::CrewOnOtherSite);
            }
            // A day already saved at their other site would be left behind there.
            if ($current !== null && $current !== $siteId && AttendanceDay::where(['employee_id' => $employeeId, 'date' => $today])->exists()) {
                throw self::refused(AttendanceRefusal::CrewMovedHasDay);
            }
            PeriodGuard::assertOpen(Period::containing(EmployeeType::DayLaborer, $today));
            if ($this->signoffs->managerState($this->signoffs->overlapping([$employeeId], $today, $today)[$employeeId] ?? [], EmployeeType::DayLaborer, $managerId) === 'signed') {
                throw self::refused(AttendanceRefusal::PeriodSigned);
            }
            $this->crew->setCrew($employeeId, $siteId, $today);
        });
    }

    public function remove(string $siteId, string $employeeId, string $managerId): void
    {
        $this->mySites($siteId, $managerId);
        $today = EffectiveDays::today();
        $this->crew->locked([$employeeId], function () use ($siteId, $employeeId, $today, $managerId): void {
            if ($this->crew->laborerSiteOn($employeeId, $today) !== $siteId) {
                throw new ModelNotFoundException;
            }
            if (AttendanceDay::where(['employee_id' => $employeeId, 'date' => $today])->exists()) {
                throw self::refused(AttendanceRefusal::CrewHasDay);
            }
            PeriodGuard::assertOpen(Period::containing(EmployeeType::DayLaborer, $today));
            if ($this->signoffs->managerState($this->signoffs->overlapping([$employeeId], $today, $today)[$employeeId] ?? [], EmployeeType::DayLaborer, $managerId) === 'signed') {
                throw self::refused(AttendanceRefusal::PeriodSigned);
            }
            $this->crew->setCrew($employeeId, null, $today);
        });
    }

    /**
     * @param  array{name_ar: string, gender: string, phone?: ?string, daily_wage: string}  $data
     * @return array{id: string, code: int, name_ar: string, proposal_id: string}
     */
    public function propose(string $siteId, array $data, string $managerId): array
    {
        $this->mySites($siteId, $managerId);
        $today = EffectiveDays::today();
        $made = DB::transaction(function () use ($siteId, $today, $data): array {
            PeriodGuard::assertOpen(Period::containing(EmployeeType::DayLaborer, $today));

            return $this->crew->propose(['name_ar' => $data['name_ar'], 'gender' => $data['gender'], 'phone' => $data['phone'] ?? null], $siteId, $today, $data['daily_wage']);
        });
        // A proposed wage is a request, so HR hears of it at once (CLAUDE.md: every manager action notifies HR).
        $site = $this->sites->names([$siteId])[$siteId] ?? null;
        foreach ($this->holders->userIds('employee.approve_wage.all') as $id) {
            $this->notifier->notifyOnce($id, 'wage_proposed', $today, [
                'employee_id' => $made['id'], 'site_name_ar' => (string) ($site['name_ar'] ?? ''), 'site_name_en' => $site['name_en'] ?? null,
            ], $made['proposal_id']);
        }

        return $made;
    }

    private static function refused(AttendanceRefusal $reason): AttendanceRefused
    {
        return AttendanceRefused::because($reason, [], [['field' => 'employee_id', 'code' => $reason->value, 'message' => __('attendance.refusal.'.$reason->value)]]);
    }

    /** @return list<string> the manager's sites; 404 unless $siteId is one of them */
    private function mySites(string $siteId, string $managerId): array
    {
        $mine = $this->sites->idsManagedBy($managerId);
        if (! in_array($siteId, $mine, true)) {
            throw new ModelNotFoundException;
        }

        return $mine;
    }
}
