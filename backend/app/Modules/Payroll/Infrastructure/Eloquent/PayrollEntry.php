<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Infrastructure\Eloquent;

use App\Modules\Audit\Infrastructure\Audited;
use App\Support\Database\HasStandardColumns;
use App\Support\Database\Precision;
use Illuminate\Database\Eloquent\Model;

/** HR's inputs for one employee, group and period; blank = 0. */
class PayrollEntry extends Model
{
    use Audited;
    use HasStandardColumns;

    protected $guarded = [];

    protected $casts = [
        'bonus' => Precision::CAST_MONEY, 'deduction' => Precision::CAST_MONEY, 'negligence' => Precision::CAST_MONEY,
        'advances' => Precision::CAST_MONEY, 'insurance_employee' => Precision::CAST_MONEY, 'insurance_company' => Precision::CAST_MONEY,
        'income_tax' => Precision::CAST_MONEY, 'lateness_exemption_minutes' => 'integer', 'travel_days' => 'integer',
        'tower_days' => 'integer', 'ot_minutes' => 'integer', 'version' => 'integer',
    ];
}
