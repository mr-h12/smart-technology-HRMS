<?php

declare(strict_types=1);

namespace App\Modules\Sites\Infrastructure\Eloquent;

use App\Modules\Audit\Infrastructure\Audited;
use App\Support\Database\HasStandardColumns;
use Illuminate\Database\Eloquent\Model;
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
}
