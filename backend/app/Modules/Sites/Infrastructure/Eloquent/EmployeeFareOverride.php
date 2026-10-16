<?php

declare(strict_types=1);

namespace App\Modules\Sites\Infrastructure\Eloquent;

use App\Support\Database\HasStandardColumns;
use App\Support\Database\Precision;
use Illuminate\Database\Eloquent\Model;

class EmployeeFareOverride extends Model
{
    use HasStandardColumns;

    protected $guarded = [];

    protected $casts = ['amount' => Precision::CAST_MONEY];
}
