<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Infrastructure\Eloquent;

use App\Modules\Audit\Infrastructure\Audited;
use App\Support\Database\HasStandardColumns;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use Audited;
    use HasStandardColumns;

    protected $guarded = [];

    protected $casts = ['data' => 'array', 'read_at' => 'datetime'];
}
