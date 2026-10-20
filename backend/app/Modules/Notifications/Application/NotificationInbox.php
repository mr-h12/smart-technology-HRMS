<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Infrastructure\Eloquent\Notification;
use App\Support\Http\Paginates;
use Illuminate\Database\Eloquent\Model;

/** The signed-in user's own notifications; another user's id is a 404, never a 403. */
final class NotificationInbox
{
    use Paginates;

    /** @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}} */
    public function list(string $userId, int $perPage): array
    {
        return $this->page(Notification::where('user_id', $userId)->orderByDesc('date')->orderByDesc('id'), $perPage);
    }

    /** @return array<string, mixed> the row; a second call keeps the first read_at */
    public function markRead(string $userId, string $id): array
    {
        $n = Notification::where('user_id', $userId)->findOrFail($id);
        if ($n->read_at === null) {
            $n->read_at = now();
            $n->save();
        }

        return $this->row($n);
    }

    /** @return array<string, mixed> */
    private function row(Model $m): array
    {
        return array_intersect_key($m->toArray(), array_flip(['id', 'type', 'date', 'data', 'read_at']));
    }
}
