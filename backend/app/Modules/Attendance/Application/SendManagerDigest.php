<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application;

use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceDay;
use App\Modules\Identity\Application\Rbac\PermissionHolders;
use App\Modules\Notifications\Application\Notifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SendManagerDigest implements ShouldQueue
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

    public function handle(Notifier $notifier, PermissionHolders $holders): void
    {
        $managerIds = $holders->userIds('attendance.record.own');
        // HR's decision on an advance or overtime can update the day without taking ownership of its attendance.
        $lastManagerEdit = DB::table('audit_log')->where('entity_type', 'attendance_days')
            ->whereColumn('entity_id', 'attendance_days.id')
            ->whereIn(DB::raw('coalesce(on_behalf_of, user_id)'), $managerIds)
            ->selectRaw('coalesce(on_behalf_of, user_id) as manager_id')
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(1);

        $managers = AttendanceDay::where(['date' => $this->date, 'source' => 'manager'])
            ->joinLateral($lastManagerEdit, 'last_manager_edit')
            ->join('users', 'users.id', '=', 'last_manager_edit.manager_id')
            ->selectRaw('users.id as manager_id, users.name as manager_name, count(*) as days')
            ->groupBy('users.id', 'users.name')->orderBy('users.name')->toBase()->get()
            ->map(fn (object $row) => (array) $row)->all();
        if ($managers === []) {
            return;
        }
        foreach ($holders->userIds('attendance.edit.all') as $hr) {
            $notifier->notifyOnce($hr, 'attendance_manager_digest', $this->date, ['date' => $this->date, 'managers' => $managers]);
        }
    }

    public function failed(Throwable $error): void
    {
        Log::error('attendance.manager_digest_failed', ['date' => $this->date, 'error' => $error->getMessage()]);
    }
}
