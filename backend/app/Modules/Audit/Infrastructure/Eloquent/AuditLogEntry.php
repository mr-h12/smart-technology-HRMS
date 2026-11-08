<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/** Read model for audit_log; rows are written only by AuditLog::record. */
class AuditLogEntry extends Model
{
    use HasUuids;
    use MassPrunable;

    /** The same expression as the audit_log_is_append_only() trigger: the DB clock decides both. */
    public const CUTOFF = "now() - interval '1 year'";

    protected $table = 'audit_log';

    public $timestamps = false;

    protected $casts = ['old_values' => 'array', 'new_values' => 'array', 'occurred_at' => 'datetime'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function onBehalfOf(): BelongsTo
    {
        return $this->belongsTo(User::class, 'on_behalf_of');
    }

    /** The DB value of CUTOFF. */
    public static function cutoff(): string
    {
        return (string) DB::scalar('select '.self::CUTOFF);
    }

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return static::whereRaw('occurred_at < '.self::CUTOFF);
    }
}
