<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Eloquent;

use App\Modules\Identity\Domain\Authentication\LockoutPolicy;
use App\Modules\Identity\Domain\Authentication\ViewAs;
use App\Modules\Identity\Domain\Authentication\ViewAsEnding;
use App\Support\Database\HasStandardColumns;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasStandardColumns;

    /** Set by the guard on a view-as session: Auth::user() is the target, this names the developer. */
    public ?ViewAs $viewAs = null;

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

    /** Active and not locked; login checks these one by one, in its own order. */
    public function canSignIn(DateTimeInterface $now): bool
    {
        return $this->is_active && ! LockoutPolicy::isLocked($this->locked_until, $now);
    }

    /** Signs this account out everywhere. A view-as on it or by it ends as revoked, so it keeps its VIEW_AS_ENDED. */
    public function revokeSessions(): void
    {
        UserSession::where(fn (Builder $q) => $q->where('impersonator_id', $this->id)
            ->orWhere(fn (Builder $q) => $q->where('user_id', $this->id)->whereNotNull('impersonator_id')))
            ->get()->each(fn (UserSession $s) => $s->endViewAs(ViewAsEnding::Revoked));
        $this->sessions()->delete();
    }
}
