<?php

declare(strict_types=1);

namespace App\Modules\Sites\Application;

use App\Modules\Sites\Domain\AllowanceMode;
use App\Modules\Sites\Infrastructure\Eloquent\SiteAllowanceRule;

/** The site allowance rules a period needs, for other modules (Payroll) to read without querying Sites' tables. */
final class AllowanceRules
{
    /** The rule in force on `from` and every later one starting on or before `to`, so EffectiveDated::at resolves any day of the period. */
    private const IN_PERIOD = '(effective_from >= ? or effective_from = (select max(r.effective_from) from site_allowance_rules r
        where r.site_id = site_allowance_rules.site_id and r.deleted_at is null and r.effective_from <= ?))';

    /**
     * One query for the period. Every requested site is a key; a site with no rule in the period has an empty list.
     *
     * @param  list<string>  $siteIds
     * @return array<string, list<array{effective_from: string, mode: AllowanceMode, value: string}>> by date within a site
     */
    public function inForce(array $siteIds, string $from, string $to): array
    {
        $rules = array_fill_keys($siteIds, []);
        $rows = SiteAllowanceRule::query()->whereIn('site_id', $siteIds)->where('effective_from', '<=', $to)
            ->whereRaw(self::IN_PERIOD, [$from, $from])
            ->orderBy('effective_from')->get();
        foreach ($rows as $r) {
            $rules[(string) $r->site_id][] = ['effective_from' => (string) $r->effective_from, 'mode' => AllowanceMode::from((string) $r->mode), 'value' => (string) $r->value];
        }

        return $rules;
    }
}
