<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Eloquent;

use App\Support\Database\HasStandardColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One signed-in device; signing out or expiring is the soft delete.
 *
 * @property Carbon $last_activity_at
 */
class UserSession extends Model
{
    use HasStandardColumns;

    protected $guarded = [];

    protected $casts = ['last_activity_at' => 'datetime'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
