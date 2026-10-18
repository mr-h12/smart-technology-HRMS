<?php

declare(strict_types=1);

namespace App\Modules\Sites\Domain;

enum LeaveCode: string
{
    case Friday = 'friday';
    case Saturday = 'saturday';
    case OfficialHoliday = 'official_holiday';
    case Sick = 'sick';
    case Rest = 'rest';
    case Absent = 'absent';
    case Other = 'other';
}
