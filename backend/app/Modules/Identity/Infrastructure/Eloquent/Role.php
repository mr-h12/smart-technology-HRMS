<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\RolePermissions;
use App\Support\Database\HasStandardColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use HasStandardColumns;

    protected $guarded = [];

    protected static function booted(): void
    {
        // A soft-deleted role grants nothing; restoring it grants again.
        static::deleted(fn (Role $r) => RolePermissions::forget((string) $r->id));
        static::restored(fn (Role $r) => RolePermissions::forget((string) $r->id));
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }
}
