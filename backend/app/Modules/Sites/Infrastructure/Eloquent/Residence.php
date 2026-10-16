<?php

declare(strict_types=1);

namespace App\Modules\Sites\Infrastructure\Eloquent;

use App\Modules\Audit\Infrastructure\Audited;
use App\Support\Database\HasStandardColumns;
use Illuminate\Database\Eloquent\Model;

class Residence extends Model
{
    use Audited;
    use HasStandardColumns;

    protected $guarded = [];
}
