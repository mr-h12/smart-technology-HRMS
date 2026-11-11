<?php

declare(strict_types=1);

namespace App\Modules\Employees\Domain;

use RuntimeException;

/** A write to an archived employee's record (owner, 2026-11-11); rendered as 409 `employee_archived` in bootstrap/app.php. */
final class EmployeeArchived extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Employee archived');
    }
}
