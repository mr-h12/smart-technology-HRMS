<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * One period day of one employee, as the assembler hands it to an engine: every dated value already resolved for the day.
 * Times are HH:MM wall-clock; minutes are the stored ones (late minutes raw, before the grace, §0a).
 */
final readonly class DayInput
{
    /**
     * @param  string  $state  work|leave|missing|not_employed|open|future
     * @param  numeric-string|null  $outsideAmount
     * @param  string  $transport  full|half|none
     * @param  numeric-string|null  $advance
     * @param  numeric-string|null  $wage  monthly salary + raise, or the daily wage, in force that day; null when no pay row is
     * @param  numeric-string  $allowance  the site allowance for the day, from the site's rule
     * @param  numeric-string  $fare  the fare for the day's site, before the transport portion
     */
    public function __construct(
        public string $date,
        public string $state,
        public ?string $leaveCode,
        public ?string $siteId,
        public ?string $in,
        public ?string $out,
        public ?string $scheduledIn,
        public ?string $scheduledOut,
        public int $lateMinutes,
        public int $otMinutes,
        public int $outsideMinutes,
        public ?string $outsideAmount,
        public string $transport,
        public ?string $advance,
        public ?string $wage,
        public string $allowance,
        public string $fare,
    ) {}
}
