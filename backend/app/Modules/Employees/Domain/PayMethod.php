<?php

declare(strict_types=1);

namespace App\Modules\Employees\Domain;

enum PayMethod: string
{
    case Bank = 'bank';
    case Cash = 'cash';
}
