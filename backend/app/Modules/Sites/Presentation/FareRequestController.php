<?php

declare(strict_types=1);

namespace App\Modules\Sites\Presentation;

use App\Modules\Identity\Presentation\RequestScope;
use App\Modules\Sites\Application\FareRequests;
use App\Modules\Sites\Application\SiteRules;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The site manager's transport fare screen for one of their sites and their change requests (`fare.request`), and HR's review
 * (`fare.manage`). Amounts arrive with Arabic or Persian digits already made ASCII (NormalizeDigits) and are decimal strings.
 */
final class FareRequestController
{
    public function __construct(private readonly FareRequests $requests) {}

    /** One site of the manager's: its team with the fare there and any request waiting. HR names the manager. */
    public function site(Request $request): JsonResponse
    {
        $ownerId = RequestScope::ownerId($request);
        $q = $request->validate([
            'site_id' => ['required', 'uuid'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'manager_id' => [Rule::requiredIf($ownerId === null), 'uuid'],
        ]);

        return ApiEnvelope::single($request, $this->requests->siteView($ownerId ?? $q['manager_id'], $q['site_id'], $q['date'] ?? self::today()));
    }

    public function store(Request $request): JsonResponse
    {
        if (is_string($reason = $request->input('reason'))) {
            $request->merge(['reason' => trim($reason)]);
        }
        $q = $request->validate([
            'employee_id' => ['required', 'uuid'],
            'site_id' => ['required', 'uuid'],
            'amount' => ['required', 'string', ...SiteRules::MONEY],
            'reason' => ['required', 'string', 'max:500'],
            'client_request_id' => ['nullable', 'uuid'],
        ], ['reason.required' => __('errors.fare_request_reason_required')]);

        return ApiEnvelope::single($request, $this->requests->submit($this->user($request), $q, self::today()), 201);
    }

    /** HR: the requests, pending by default (`status`: pending, approved, refused or all). */
    public function index(Request $request): JsonResponse
    {
        $q = $request->validate([
            'status' => ['sometimes', Rule::in(['pending', 'approved', 'refused', 'all'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $page = $this->requests->list($q['status'] ?? null, (int) ($q['per_page'] ?? 20), (int) ($q['page'] ?? 1));

        return ApiEnvelope::collection($request, $page['data'], $page['pagination']);
    }

    public function show(Request $request, string $fareRequest): JsonResponse
    {
        return ApiEnvelope::single($request, $this->requests->show($fareRequest));
    }

    /** The requested amount, or HR's revision in `amount`, from `effective_from` (today in Cairo when absent). */
    public function approve(Request $request, string $fareRequest): JsonResponse
    {
        $q = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'amount' => ['nullable', 'string', ...SiteRules::MONEY],
            'effective_from' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        return ApiEnvelope::single($request, $this->requests->decide($fareRequest, (int) $q['version'], true, $q['amount'] ?? null, $q['effective_from'] ?? self::today(), null, $this->user($request), self::today()));
    }

    /** HR's reason is required; the manager reads it on their request. */
    public function refuse(Request $request, string $fareRequest): JsonResponse
    {
        if (is_string($reason = $request->input('reason'))) {
            $request->merge(['reason' => trim($reason)]);
        }
        $q = $request->validate(
            ['version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:500']],
            ['reason.required' => __('errors.refusal_reason_required')],
        );

        return ApiEnvelope::single($request, $this->requests->decide($fareRequest, (int) $q['version'], false, null, null, $q['reason'], $this->user($request), self::today()));
    }

    /** The manager's own requests and HR's decisions; never anyone else's. */
    public function mine(Request $request): JsonResponse
    {
        $q = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $page = $this->requests->mine($this->user($request), (int) ($q['per_page'] ?? 20), (int) ($q['page'] ?? 1));

        return ApiEnvelope::collection($request, $page['data'], $page['pagination']);
    }

    public function mineOne(Request $request, string $fareRequest): JsonResponse
    {
        return ApiEnvelope::single($request, $this->requests->mineOne($this->user($request), $fareRequest));
    }

    private function user(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }

    private static function today(): string
    {
        return now('Africa/Cairo')->toDateString();
    }
}
