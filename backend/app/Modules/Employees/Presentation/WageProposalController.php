<?php

declare(strict_types=1);

namespace App\Modules\Employees\Presentation;

use App\Modules\Employees\Application\DayCrew;
use App\Modules\Employees\Application\EmployeeRules;
use App\Modules\Employees\Domain\WageProposalStatus;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HR's review of the daily wages site managers proposed (`employee.approve_wage.all`): for the day laborers they added, and since #79
 * for existing day laborers (`employee.propose_wage.own`).
 */
final class WageProposalController
{
    public function __construct(private readonly DayCrew $crew) {}

    public function index(Request $request): JsonResponse
    {
        $q = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $page = $this->crew->pending((int) ($q['per_page'] ?? 20), (int) ($q['page'] ?? 1));

        return ApiEnvelope::collection($request, $page['data'], $page['pagination']);
    }

    public function approve(Request $request, string $proposal): JsonResponse
    {
        $q = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'daily_wage' => ['nullable', 'string', EmployeeRules::MONEY, EmployeeRules::POSITIVE],
            // a wage change only, where it is required: the day HR's approved wage applies from (a new laborer's is the hire date)
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $this->crew->decide($proposal, (int) $q['version'], true, $q['daily_wage'] ?? null, $this->hr($request), self::today(), null, $q['effective_from'] ?? null);

        return ApiEnvelope::single($request, ['id' => $proposal, 'status' => WageProposalStatus::Approved->value]);
    }

    /** HR's reason is required (owner, 2026-11-19); the proposing site manager reads it on their request. */
    public function refuse(Request $request, string $proposal): JsonResponse
    {
        if (is_string($reason = $request->input('reason'))) {
            $request->merge(['reason' => trim($reason)]);
        }
        $q = $request->validate(
            ['version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:500']],
            ['reason.required' => __('errors.refusal_reason_required')],
        );
        $this->crew->decide($proposal, (int) $q['version'], false, null, $this->hr($request), self::today(), $q['reason']);

        return ApiEnvelope::single($request, ['id' => $proposal, 'status' => WageProposalStatus::Refused->value]);
    }

    /**
     * A site manager's new daily wage for a day laborer in their reach today (`employee.propose_wage`, `employee.scope`; #79), with an
     * optional reason HR reads. 201, or 200 with the first answer when the form's `client_request_id` was already used.
     */
    public function propose(Request $request, string $employee): JsonResponse
    {
        if (is_string($reason = $request->input('reason'))) {
            $request->merge(['reason' => trim($reason) === '' ? null : trim($reason)]);
        }
        $q = $request->validate([
            'daily_wage' => ['required', 'string', EmployeeRules::MONEY, EmployeeRules::POSITIVE],
            'reason' => ['nullable', 'string', 'max:500'],
            'client_request_id' => ['nullable', 'uuid'],
        ]);
        $made = $this->crew->proposeWage($employee, $q['daily_wage'], $q['reason'] ?? null, $q['client_request_id'] ?? null, $this->hr($request), self::today());

        return ApiEnvelope::single($request, ['id' => $made['id'], 'status' => $made['status']])->setStatusCode($made['replayed'] ? 200 : 201);
    }

    /** The site manager's own proposals and their outcome (`employee.propose_laborer` or `employee.propose_wage`); never anyone else's. */
    public function mine(Request $request): JsonResponse
    {
        $q = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $page = $this->crew->mine($this->hr($request), (int) ($q['per_page'] ?? 20), (int) ($q['page'] ?? 1));

        return ApiEnvelope::collection($request, $page['data'], $page['pagination']);
    }

    /** One of the site manager's own proposals; another manager's id is a 404. */
    public function mineOne(Request $request, string $proposal): JsonResponse
    {
        return ApiEnvelope::single($request, $this->crew->mineOne($this->hr($request), $proposal));
    }

    /** The signed-in user: HR deciding, or the site manager proposing or reading their own proposals. */
    private function hr(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }

    /** Cairo's date: a refused laborer's last day, and the day a wage change is proposed on */
    private static function today(): string
    {
        return now('Africa/Cairo')->toDateString();
    }
}
