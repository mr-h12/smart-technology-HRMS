<?php

declare(strict_types=1);

namespace App\Modules\Sites\Infrastructure\Eloquent;

use App\Modules\Audit\Infrastructure\Audited;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Support\Database\HasStandardColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    use Audited;
    use HasStandardColumns;

    protected $guarded = [];

    protected $casts = ['outside_alexandria' => 'boolean', 'active' => 'boolean'];

    /** @return HasMany<SiteAllowanceRule, $this> */
    public function allowanceRules(): HasMany
    {
        return $this->hasMany(SiteAllowanceRule::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function managers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'manager_sites', 'site_id', 'manager_id');
    }
}
