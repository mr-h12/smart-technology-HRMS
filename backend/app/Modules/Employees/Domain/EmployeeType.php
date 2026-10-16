<?php

declare(strict_types=1);

namespace App\Modules\Employees\Domain;

enum EmployeeType: string
{
    case Office = 'office';
    case Engineer = 'engineer';
    case PermanentWorker = 'permanent_worker';
    case DayLaborer = 'day_laborer';
}
