<?php

declare(strict_types=1);

namespace App\Modules\Audit\Application;

use App\Modules\Audit\Infrastructure\Eloquent\AuditLogEntry;
use App\Support\Http\Paginates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

final class AuditLogQuery
{
    use Paginates;

    /**
     * @param  array<string, mixed>  $filters  from/to are inclusive Cairo dates (Y-m-d)
     * @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}}
     */
    public function list(array $filters): array
    {
        $query = AuditLogEntry::query()->with('user:id,name')->orderByDesc('occurred_at')->orderByDesc('id');
        foreach (['user_id', 'entity_type', 'entity_id'] as $key) {
            if (isset($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        if (isset($filters['from'])) {
            $query->where('occurred_at', '>=', Carbon::parse($filters['from'], 'Africa/Cairo')->startOfDay()->utc());
        }
        if (isset($filters['to'])) {
            $query->where('occurred_at', '<', Carbon::parse($filters['to'], 'Africa/Cairo')->addDay()->startOfDay()->utc());
        }

        return $this->page($query, $filters['per_page'] ?? 20);
    }

    /** @return array<string, mixed> */
    private function row(Model $m): array
    {
        /** @var AuditLogEntry $m */
        $user = $m->user;

        return [
            ...array_diff_key($m->toArray(), ['user' => true]),
            'user' => $user === null ? null : ['id' => (string) $user->id, 'name' => $user->name],
        ];
    }
}
