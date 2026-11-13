<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Eloquent;

use App\Modules\Audit\Infrastructure\Audited;
use App\Support\Database\HasStandardColumns;
use Illuminate\Database\Eloquent\Model;

final class AttendanceSignoff extends Model
{
    use Audited;
    use HasStandardColumns;

    protected $guarded = [];

    protected $casts = ['version' => 'integer', 'signed_at' => 'datetime', 'returned_at' => 'datetime'];
}
