<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Eloquent;

use App\Modules\Audit\Infrastructure\Audited;
use App\Support\Database\HasStandardColumns;
use App\Support\Database\Precision;
use Illuminate\Database\Eloquent\Model;

class AttendanceDay extends Model
{
    use Audited;
    use HasStandardColumns;

    protected $guarded = [];

    protected $casts = ['outside_amount' => Precision::CAST_MONEY, 'advance_amount' => Precision::CAST_MONEY, 'version' => 'integer'];
}
