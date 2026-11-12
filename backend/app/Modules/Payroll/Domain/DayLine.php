<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/** One day of a payroll line: its per-day components, its cost for site costing (§8) and its flags. */
final readonly class DayLine
{
    /**
     * @param  array<string, numeric-string>  $parts  Component value → amount
     * @param  numeric-string  $dayCost
     * @param  list<string>  $flags
     */
    public function __construct(
        public string $date,
        public ?string $siteId,
        public array $parts,
        public string $dayCost,
        public array $flags,
    ) {}
}
