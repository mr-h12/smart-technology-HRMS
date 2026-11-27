<?php

declare(strict_types=1);

namespace App\Modules\Employees\Domain;

use RuntimeException;

/**
 * A site manager's new day laborer looks like someone already recorded (owner, 2026-11-19); rendered as 409 `laborer_exists` in
 * bootstrap/app.php. `name`: a day laborer has that full name; the manager adds that person or resends confirming a different one.
 * `national_id`: an employee holds the ID; the match is named only when that employee is in the manager's reach, else HR is the
 * contact. The match never carries the national ID, a wage or anything else beyond what the crew search shows.
 *
 * @phpstan-type Match array{id: string, code: int, name_ar: string, name_en: ?string, site: ?array<string, mixed>, here: bool, can_add: bool}
 */
final class LaborerExists extends RuntimeException
{
    public const NAME = 'name';

    public const NATIONAL_ID = 'national_id';

    /** @param  array<string, mixed>|null  $match */
    public function __construct(public readonly string $reason, public readonly ?array $match, public readonly bool $ambiguous = false)
    {
        parent::__construct('Day laborer already recorded: '.$reason);
    }
}
