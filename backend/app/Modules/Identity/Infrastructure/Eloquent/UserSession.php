<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Eloquent;

use App\Modules\Audit\Infrastructure\AuditLog;
use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Domain\Authentication\ViewAsEnding;
use App\Support\Database\HasStandardColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One signed-in device; signing out or expiring is the soft delete.
 * A view-as session belongs to the target and names the developer in impersonator_id.
 *
 * @property Carbon $last_activity_at
 * @property Carbon|null $expires_at
 */
class UserSession extends Model
{
    use HasStandardColumns;

    protected $guarded = [];

    protected $casts = ['last_activity_at' => 'datetime', 'expires_at' => 'datetime'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Soft-deletes this view-as session and records it as the developer; false (and no row) when it had already ended. */
    public function endViewAs(ViewAsEnding $ending, ?string $developerSessionId = null): bool
    {
        return DB::transaction(function () use ($ending, $developerSessionId): bool {
            if (self::whereKey($this->id)->delete() === 0) {
                return false;
            }
            AuditLog::record(IdentityAuditEvents::VIEW_AS_ENDED, 'users', (string) $this->user_id, ['session_id' => (string) $this->id],
                ['ended_by' => $ending->value, ...($developerSessionId === null ? [] : ['session_id' => $developerSessionId])], (string) $this->impersonator_id);

            return true;
        });
    }
}
