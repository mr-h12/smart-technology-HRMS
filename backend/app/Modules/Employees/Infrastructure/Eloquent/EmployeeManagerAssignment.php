<?php

declare(strict_types=1);

namespace App\Modules\Employees\Infrastructure\Eloquent;

use App\Modules\Audit\Infrastructure\Audited;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Support\Database\HasStandardColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeManagerAssignment extends Model
{
    use Audited;
    use HasStandardColumns;

    protected $guarded = [];

    /** @return BelongsTo<User, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
