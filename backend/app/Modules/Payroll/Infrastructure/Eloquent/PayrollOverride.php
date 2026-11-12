<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Infrastructure\Eloquent;

use App\Modules\Audit\Infrastructure\Audited;
use App\Support\Database\HasStandardColumns;
use App\Support\Database\Precision;
use Illuminate\Database\Eloquent\Model;

/** HR's replacement of one computed component, kept apart from it; reverting soft-deletes it with a reason. */
class PayrollOverride extends Model
{
    use Audited;
    use HasStandardColumns;

    protected $guarded = [];

    protected $casts = ['amount' => Precision::CAST_MONEY, 'computed_at_override' => Precision::CAST_MONEY, 'version' => 'integer'];
}
