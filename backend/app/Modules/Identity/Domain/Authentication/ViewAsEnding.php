<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Authentication;

/** Why a view-as ended; the value is VIEW_AS_ENDED's `ended_by`. */
enum ViewAsEnding: string
{
    case Returned = 'returned';
    case Logout = 'logout';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
