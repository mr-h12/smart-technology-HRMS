<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Eloquent;

use App\Support\Database\HasStandardColumns;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasStandardColumns;

    protected $guarded = [];
}
