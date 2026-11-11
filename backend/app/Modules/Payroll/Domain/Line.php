<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/** One employee's computed payroll line for a period: components (before any override), the days, and the flags. */
final readonly class Line
{
    /**
     * @param  array<string, numeric-string>  $components  Component value → computed amount
     * @param  list<DayLine>  $days
     * @param  list<string>  $flags
     */
    public function __construct(
        public array $components,
        public array $days,
        public array $flags,
    ) {}
}
