<?php

declare(strict_types=1);

namespace App\Modules\Employees\Domain;

use RuntimeException;

/** A termination date while the employee has open items (owner, 2026-11-11); rendered as 409 `employee_has_open_items` with the items in bootstrap/app.php. */
final class EmployeeHasOpenItems extends RuntimeException
{
    /** @param  list<array<string, string>>  $items  each {kind, id, ...}: a leave request also carries status, from_date and to_date */
    public function __construct(public readonly array $items)
    {
        parent::__construct('Employee has open items: '.count($items));
    }
}
