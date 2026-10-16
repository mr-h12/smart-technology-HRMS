<?php

declare(strict_types=1);

namespace App\Modules\Sites\Domain;

enum AllowanceMode: string
{
    case None = 'none';
    case ShareOfWage = 'share_of_wage';
    case Fixed = 'fixed';
}
