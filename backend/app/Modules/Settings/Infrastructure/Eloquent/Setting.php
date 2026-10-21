<?php

declare(strict_types=1);

namespace App\Modules\Settings\Infrastructure\Eloquent;

use App\Modules\Audit\Infrastructure\Audited;
use App\Support\Database\HasStandardColumns;
use Illuminate\Database\Eloquent\Model;

/** One business-rule parameter (key → string value). */
class Setting extends Model
{
    use Audited;
    use HasStandardColumns;

    protected $guarded = [];
}
