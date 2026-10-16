<?php

declare(strict_types=1);

namespace App\Modules\Sites\Domain;

enum SiteKind: string
{
    case Work = 'work';
    case Leave = 'leave';
}
