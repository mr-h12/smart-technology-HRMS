<?php

declare(strict_types=1);

namespace App\Modules\Sites\Domain;

/** A site manager's transport fare change request, as HR decides it (`fare_change_requests.status`). */
enum FareRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Refused = 'refused';
}
