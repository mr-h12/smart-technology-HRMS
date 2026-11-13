<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application;

use App\Modules\Identity\Application\Rbac\PermissionHolders;
use App\Modules\Notifications\Application\Notifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The 21:00 alert (hrms-module-plan, 1c; owner, 2026-11-05): one notification per (user, date) to each holder of attendance.edit.all,
 * listing per open period the unrecorded days, the open field days (an arrival and no departure) and the day's crew changes
 * (AttendanceGrid::missingCounts). Counts only, never names (§9). Counts are cumulative, so the next day's run also covers a day
 * whose run failed.
 */
final class SendMissingDaysAlert implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $date)
    {
        $this->onQueue('maintenance');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(AttendanceGrid $grid, Notifier $notifier, PermissionHolders $holders): void
    {
        $groups = array_values(array_filter($grid->missingCounts($this->date), fn (array $g) => $g['missing'] + $g['open'] + $g['crew_changes'] > 0));
        $skipped = $grid->skippedBySite($this->date);
        foreach ($holders->userIds('attendance.edit.all') as $id) {
            if ($groups !== []) {
                $notifier->notifyOnce($id, 'attendance_missing_days', $this->date, ['date' => $this->date, 'groups' => $groups]);
            }
            if ($skipped['sites'] !== [] || $skipped['engineers'] !== []) {
                $notifier->notifyOnce($id, 'attendance_skipped_days', $this->date, ['date' => $this->date, ...$skipped]);
            }
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('attendance.missing_days_alert_failed', ['date' => $this->date, 'error' => $e->getMessage()]);
    }
}
