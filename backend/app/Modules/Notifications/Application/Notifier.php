<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Infrastructure\Eloquent\Notification;
use Illuminate\Database\UniqueConstraintViolationException;

final class Notifier
{
    /**
     * One notification per (user, type, date); a retried job adds nothing.
     *
     * @param  array<string, mixed>  $data  counts and ids only, never personal data (§9)
     * @return bool false when the row already exists
     */
    public function notifyOnce(string $userId, string $type, string $date, array $data): bool
    {
        $key = ['user_id' => $userId, 'type' => $type, 'date' => $date];
        if (Notification::withTrashed()->where($key)->exists()) {
            return false;
        }
        try {
            Notification::create([...$key, 'data' => $data]);
        } catch (UniqueConstraintViolationException) {
            return false;   // a concurrent run inserted it first
        }

        return true;
    }
}
