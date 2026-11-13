<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Presentation;

use App\Modules\Attendance\Application\EffectiveDays;
use App\Modules\Attendance\Application\SiteDay;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The manager's day screen (design Part 2); `attendance.record` is held only at `own`, so the signed-in user is the manager (P3). */
final class SiteDayController
{
    public function __construct(private readonly SiteDay $siteDay) {}

    public function show(Request $request): JsonResponse
    {
        $q = $request->validate(['site_id' => ['required', 'uuid'], 'date' => ['sometimes', 'date_format:Y-m-d']]);

        return ApiEnvelope::single($request, $this->siteDay->read($q['site_id'], $q['date'] ?? EffectiveDays::today(), (string) $request->user()?->getAuthIdentifier()));
    }

    /** The day's save; the response is the day as show() returns it, with the new versions. */
    public function store(Request $request): JsonResponse
    {
        /** @var array{site_id: string, date: string, rows: list<array<string, mixed>>} $body */
        $body = $request->validate(SiteDay::FORMAT, [
            'paper_decision_date.missing' => __('errors.advance_paper_date_pending'),
            'rows.*.paper_decision_date.missing' => __('errors.advance_paper_date_pending'),
        ]);

        return ApiEnvelope::single($request, $this->siteDay->save($body, (string) $request->user()?->getAuthIdentifier()));
    }
}
