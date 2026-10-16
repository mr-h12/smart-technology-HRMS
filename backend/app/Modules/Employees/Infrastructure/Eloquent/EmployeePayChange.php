<?php

declare(strict_types=1);

namespace App\Modules\Employees\Infrastructure\Eloquent;

use App\Support\Database\HasStandardColumns;
use App\Support\Database\Precision;
use Illuminate\Database\Eloquent\Model;

class EmployeePayChange extends Model
{
    use HasStandardColumns;

    protected $guarded = [];

    protected $casts = [
        'salary' => Precision::CAST_MONEY,
        'raise' => Precision::CAST_MONEY,
        'insured_basic' => Precision::CAST_MONEY,
        'daily_wage' => Precision::CAST_MONEY,
    ];
}
