<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/** The business-rule settings an engine needs, for one employee type (calculations §0, §0a, §7). */
final readonly class Rates
{
    /**
     * @param  numeric-string  $monthDays
     * @param  numeric-string  $dayHours
     * @param  numeric-string  $overtime  the overtime multiplier
     * @param  int  $grace  lateness grace minutes, subtracted once per day
     * @param  numeric-string  $multiplier  the lateness multiplier of the employee's type
     * @param  numeric-string  $sickPercent  the share of a day rate a sick day pays
     */
    public function __construct(
        public string $monthDays,
        public string $dayHours,
        public string $overtime,
        public int $grace,
        public string $multiplier,
        public string $sickPercent,
    ) {}
}
