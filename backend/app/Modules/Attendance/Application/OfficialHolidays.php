<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application;

use App\Modules\Attendance\Domain\Period;
use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceDay;
use App\Modules\Attendance\Infrastructure\Eloquent\OfficialHoliday;
use App\Modules\Attendance\Infrastructure\PeriodGuard;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Sites\Domain\LeaveCode;
use App\Modules\Sites\Domain\SiteKind;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use App\Support\Http\Paginates;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The official-holiday list: named periods of one or more days that never overlap. A holiday derives every day of it for every group, so a
 * write needs each group's periods on its old and new days open. Deleting, moving or shortening one clears the holiday days a lock stored
 * on the dates it no longer covers (a reopened period), so they are derived again or missing.
 */
final class OfficialHolidays
{
    use Paginates;

    /** @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}} */
    public function list(?int $year, int $perPage): array
    {
        $query = OfficialHoliday::query()->orderBy('date');
        if ($year !== null) {
            $query->where('date', '<=', "{$year}-12-31")->where('end_date', '>=', "{$year}-01-01");
        }

        return $this->page($query, $perPage);
    }

    /**
     * @param  array{date: string, end_date?: ?string, name: string}  $data  no end_date: the one day
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $data['end_date'] ??= $data['date'];

        return $this->write(function () use ($data): OfficialHoliday {
            $this->assertOpen($data['date'], $data['end_date']);
            $this->assertFree($data['date'], $data['end_date']);

            return OfficialHoliday::create($data);
        });
    }

    /**
     * @param  array{date?: string, end_date?: string, name?: string}  $data  date and end_date come together
     * @return array<string, mixed>
     */
    public function update(string $id, array $data): array
    {
        return $this->write(function () use ($id, $data): OfficialHoliday {
            $holiday = OfficialHoliday::lockForUpdate()->findOrFail($id);
            [$from, $to] = [(string) $holiday->date, (string) $holiday->end_date];
            $this->assertOpen($from, $to);
            if (isset($data['date'], $data['end_date'])) {
                $this->assertOpen($data['date'], $data['end_date']);
                $this->assertFree($data['date'], $data['end_date'], $id);
            }
            $holiday->update($data);
            $this->clearStored($from, $to, [(string) $holiday->date, (string) $holiday->end_date]);

            return $holiday;
        });
    }

    public function delete(string $id): void
    {
        DB::transaction(function () use ($id): void {
            $holiday = OfficialHoliday::lockForUpdate()->findOrFail($id);
            $this->assertOpen((string) $holiday->date, (string) $holiday->end_date);
            $holiday->delete();
            $this->clearStored((string) $holiday->date, (string) $holiday->end_date);
        });
    }

    /**
     * Soft-deletes the lock's stored holiday days from $from to $to, outside $keep, as a clear does (version+1, audited); HR's, imported and
     * leave days stay.
     *
     * @param  list<string>  $keep  the holiday's new first and last day, whose days stay; [] for none
     */
    private function clearStored(string $from, string $to, array $keep = []): void
    {
        $types = Site::withTrashed()->where(['kind' => SiteKind::Leave->value, 'leave_code' => LeaveCode::OfficialHoliday->value])->select('id');
        $rows = AttendanceDay::where('source', 'derived')->whereBetween('date', [$from, $to])->whereIn('site_id', $types);
        if ($keep !== []) {
            $rows->whereNotBetween('date', $keep);
        }
        foreach ($rows->lockForUpdate()->get() as $row) {
            $row->version = $row->version + 1;
            $row->save();
            $row->delete();
        }
    }

    /**
     * @param  Closure(): OfficialHoliday  $write
     * @return array<string, mixed>
     */
    private function write(Closure $write): array
    {
        try {
            return $this->row(DB::transaction($write));
        } catch (QueryException $e) {
            if ($e->getCode() !== '23P01') {
                throw $e;
            }
            // official_holidays_no_overlap: a racing write of overlapping dates won.
            throw ValidationException::withMessages(['date' => __('errors.holiday_overlap')]);
        }
    }

    /** Every group's every period from $from to $to. */
    private function assertOpen(string $from, string $to): void
    {
        foreach (EmployeeType::cases() as $type) {
            for ($p = Period::containing($type, $from); $p->start <= $to; $p = $p->next()) {
                PeriodGuard::assertOpen($p);
            }
        }
    }

    /** No other live holiday shares a date with $from to $to. */
    private function assertFree(string $from, string $to, ?string $except = null): void
    {
        $other = OfficialHoliday::where('date', '<=', $to)->where('end_date', '>=', $from);
        if ($except !== null) {
            $other->whereKeyNot($except);
        }
        if ($other->exists()) {
            throw ValidationException::withMessages(['date' => __('errors.holiday_overlap')]);
        }
    }
}
