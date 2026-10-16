<?php

declare(strict_types=1);

namespace App\Modules\Employees\Infrastructure\Eloquent;

use App\Support\Database\HasStandardColumns;
use Illuminate\Database\Eloquent\Model;

class EmployeeTypeChange extends Model
{
    use HasStandardColumns;

    protected $guarded = [];
}
