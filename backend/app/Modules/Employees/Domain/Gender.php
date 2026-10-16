<?php

declare(strict_types=1);

namespace App\Modules\Employees\Domain;

enum Gender: string
{
    case Male = 'male';
    case Female = 'female';
}
