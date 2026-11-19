<?php

declare(strict_types=1);

namespace App\Modules\Sites\Domain;

use RuntimeException;

/**
 * The employee already has a fare change request waiting for HR at this site; rendered as 409 `fare_request_pending` in bootstrap/app.php
 * with that request (id, requested amount, when, and whether this manager asked), so the screen points at it instead of adding another.
 */
final class FareRequestPending extends RuntimeException
{
    /** @param  array{id: string, requested_amount: string, created_at: ?string, mine: bool}  $pending */
    public function __construct(public readonly array $pending)
    {
        parent::__construct('A fare change request is already pending');
    }
}
