<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Model half of the standardColumns() block: UUIDv7 key + soft deletes. */
trait HasStandardColumns
{
    use HasUuids;
    use SoftDeletes;
}
