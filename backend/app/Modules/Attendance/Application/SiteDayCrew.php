<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application;

use App\Modules\Attendance\Domain\AttendanceRefusal;
use App\Modules\Attendance\Domain\AttendanceRefused;
use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceDay;
use App\Modules\Employees\Application\DayCrew;
use App\Modules\Employees\Application\EmployeeReach;
use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Employees\Domain\LaborerExists;
use App\Modules\Identity\Application\Rbac\PermissionHolders;
use App\Modules\Notifications\Application\Notifier;
use App\Modules\Sites\Application\SiteService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;

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
        private readonly EmployeeRoster $roster,
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
        $this->crew->locked([$employeeId], function () use ($siteId, $employeeId, $mine, $today): void {
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
            $this->crew->setCrew($employeeId, $siteId, $today);
        });
    }

    public function remove(string $siteId, string $employeeId, string $managerId): void
    {
        $this->mySites($siteId, $managerId);
        $today = EffectiveDays::today();
        $this->crew->locked([$employeeId], function () use ($siteId, $employeeId, $today): void {
            if ($this->crew->laborerSiteOn($employeeId, $today) !== $siteId) {
                throw new ModelNotFoundException;
            }
            if (AttendanceDay::where(['employee_id' => $employeeId, 'date' => $today])->exists()) {
                throw self::refused(AttendanceRefusal::CrewHasDay);
            }
            $this->crew->setCrew($employeeId, null, $today);
        });
    }

    /**
     * A new day laborer on today's crew with a proposed wage for HR (owner, 2026-11-19 for the checks):
     * - the same form id again (a double tap, a retry, or two at once) returns the first answer and adds nobody;
     * - a national ID someone already holds is refused, naming them only when they are in this manager's reach;
     * - a day laborer with the same full name is shown first (or `ambiguous` when several share it), unless $data['confirm_new'] says
     *   the manager checked and it is someone else.
     *
     * @param  array{name_ar: string, gender: string, phone?: ?string, national_id?: ?string, daily_wage: string, client_request_id?: ?string, confirm_new?: bool}  $data
     * @return array{id: string, code: int, name_ar: string, proposal_id: string}
     */
    public function propose(string $siteId, array $data, string $managerId): array
    {
        $this->mySites($siteId, $managerId);
        $today = EffectiveDays::today();
        $key = $data['client_request_id'] ?? null;
        if ($key !== null && ($first = $this->crew->byClientRequest($key, $managerId, $data)) !== null) {
            return $first;
        }
        $nationalId = $data['national_id'] ?? null;
        if ($nationalId !== null) {
            $this->refuseTakenNationalId($nationalId, $siteId, $managerId, $today);
        }
        if (! ($data['confirm_new'] ?? false)) {
            $same = $this->search($siteId, $data['name_ar'], $managerId);
            if ($same['match'] !== null || $same['ambiguous']) {
                throw new LaborerExists(LaborerExists::NAME, $same['match'], $same['ambiguous']);
            }
        }
        $identity = ['name_ar' => $data['name_ar'], 'gender' => $data['gender'], 'phone' => $data['phone'] ?? null, ...($nationalId === null ? [] : ['national_id' => $nationalId])];
        try {
            $made = $this->crew->propose($identity, $siteId, $today, $data['daily_wage'], $key);
        } catch (UniqueConstraintViolationException $e) {
            // Lost a race: the same form id (replay the winner's answer) or the same national ID (refuse as above).
            if ($key !== null && ($first = $this->crew->byClientRequest($key, $managerId, $data)) !== null) {
                return $first;
            }
            if ($nationalId !== null) {
                $this->refuseTakenNationalId($nationalId, $siteId, $managerId, $today);
            }
            throw $e;
        }
        // A proposed wage is a request, so HR hears of it at once (CLAUDE.md: every manager action notifies HR). No amount in the
        // payload (owner, 2026-11-19): HR reads the wage on the proposal itself.
        $site = $this->sites->names([$siteId])[$siteId] ?? null;
        foreach ($this->holders->userIds('employee.approve_wage.all') as $id) {
            $this->notifier->notifyOnce($id, 'wage_proposed', $today, [
                'employee_id' => $made['id'], 'site_name_ar' => (string) ($site['name_ar'] ?? ''), 'site_name_en' => $site['name_en'] ?? null,
            ], $made['proposal_id']);
        }

        return $made;
    }

    /** Throws when an employee already holds $nationalId: their crew-search row when this manager reaches them, else none (HR is the contact). */
    private function refuseTakenNationalId(string $nationalId, string $siteId, string $managerId, string $today): void
    {
        $holder = $this->roster->idWithNationalId($nationalId);
        if ($holder === null) {
            return;
        }
        if (! EmployeeReach::reaches($holder, $managerId, $today)) {
            throw new LaborerExists(LaborerExists::NATIONAL_ID, null);
        }
        $e = $this->crew->identityOf($holder);
        $found = $this->search($siteId, (string) $e['code'], $managerId)['match'];
        throw new LaborerExists(LaborerExists::NATIONAL_ID, $found ?? [...$e, 'site' => null, 'here' => false, 'can_add' => false]);
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
