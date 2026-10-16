<?php

declare(strict_types=1);

namespace App\Modules\Employees\Infrastructure\Eloquent;

use App\Modules\Audit\Infrastructure\Audited;
use App\Support\Database\HasStandardColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use Audited;
    use HasStandardColumns;

    protected $guarded = [];

    /** @return HasMany<EmployeeTypeChange, $this> */
    public function typeChanges(): HasMany
    {
        return $this->hasMany(EmployeeTypeChange::class);
    }

    /** @return HasMany<EmployeePayChange, $this> */
    public function payChanges(): HasMany
    {
        return $this->hasMany(EmployeePayChange::class);
    }

    /** @return HasMany<EmployeeDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }
}
