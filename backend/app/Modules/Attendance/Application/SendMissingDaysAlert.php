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
 * The missing-days bell (hrms-module-plan, 1c): per open period, how many days are still unrecorded up to $date,
 * sent once per (user, date) to each holder of attendance.edit.all. Counts only, never names (§9).
 * Counts are cumulative, so the next day's run also covers a day whose run failed.
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
        $groups = array_values(array_filter($grid->missingCounts($this->date), fn (array $g) => $g['missing'] > 0));
        if ($groups === []) {
            return;
        }
        foreach ($holders->userIds('attendance.edit.all') as $id) {
            $notifier->notifyOnce($id, 'attendance_missing_days', $this->date, ['date' => $this->date, 'groups' => $groups]);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('attendance.missing_days_alert_failed', ['date' => $this->date, 'error' => $e->getMessage()]);
    }
}
