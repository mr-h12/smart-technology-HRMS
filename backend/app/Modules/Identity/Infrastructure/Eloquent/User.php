<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Eloquent;

use App\Support\Database\HasStandardColumns;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasStandardColumns;

    protected $guarded = [];

    protected $hidden = ['password'];

    protected $casts = ['password' => 'hashed', 'is_active' => 'boolean', 'locked_until' => 'datetime'];

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return HasMany<UserSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }
}
