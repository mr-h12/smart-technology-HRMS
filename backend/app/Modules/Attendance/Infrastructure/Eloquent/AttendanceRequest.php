<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Eloquent;

use App\Modules\Audit\Infrastructure\Audited;
use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use App\Support\Database\HasStandardColumns;
use App\Support\Database\Precision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AttendanceRequest extends Model
{
    use Audited;
    use HasStandardColumns;

    protected $guarded = [];

    protected $casts = ['amount' => Precision::CAST_MONEY, 'minutes' => 'integer', 'version' => 'integer'];

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
