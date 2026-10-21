<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** The one writer of audit_log rows; runs inside the caller's transaction. */
final class AuditLog
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public static function record(string $event, string $entityType, ?string $entityId, ?array $oldValues = null, ?array $newValues = null, ?string $actorId = null): void
    {
        $request = request();
        $requestId = $request->attributes->get('request_id'); // set by AddRequestId (outside the module layers)
        $userAgent = $request->userAgent();

        DB::table('audit_log')->insert([
            'id' => (string) Str::uuid7(),
            // hasUser(): a save inside the bearer guard must not re-enter it.
            'user_id' => $actorId ?? (Auth::hasUser() ? Auth::id() : null),
            'event' => $event,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => self::encode($oldValues),
            'new_values' => self::encode($newValues),
            'ip_address' => $request->ip(),
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 512),
            'request_id' => is_string($requestId) ? $requestId : null,
            'occurred_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>|null  $values */
    private static function encode(?array $values): ?string
    {
        if ($values === null) {
            return null;
        }
        array_walk_recursive($values, static function (mixed $v): void {
            if (is_float($v)) {
                throw new InvalidArgumentException('Audit values must not carry floats; use decimal strings.');
            }
        });

        return json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
