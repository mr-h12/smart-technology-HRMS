<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain;

/** One office person-day reduced from its punches (FP-3): times are HH:MM, at most one of them null; $punchCount counts the distinct punches read. */
final readonly class PunchedDay
{
    /** @param  list<PunchFlag>  $flags  in enum order */
    public function __construct(
        public ?string $inTime,
        public ?string $outTime,
        public array $flags,
        public int $punchCount,
    ) {}
}
