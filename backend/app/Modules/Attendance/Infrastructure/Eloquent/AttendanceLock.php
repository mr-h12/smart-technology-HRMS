<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Eloquent;

use App\Support\Database\HasStandardColumns;
use Illuminate\Database\Eloquent\Model;

/** Not Audited: lock and reopen are written as their own audit events. */
class AttendanceLock extends Model
{
    use HasStandardColumns;

    protected $guarded = [];

    protected $casts = ['locked_at' => 'datetime', 'reopened_at' => 'datetime'];
}
