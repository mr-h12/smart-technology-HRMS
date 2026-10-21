<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Read model for audit_log; rows are written only by AuditLog::record. */
class AuditLogEntry extends Model
{
    use HasUuids;

    protected $table = 'audit_log';

    public $timestamps = false;

    protected $casts = ['old_values' => 'array', 'new_values' => 'array', 'occurred_at' => 'datetime'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
