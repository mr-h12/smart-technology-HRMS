<?php

declare(strict_types=1);

namespace App\Modules\Leave\Presentation;

use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Modules\Identity\Presentation\RequestScope;
use App\Modules\Leave\Application\LeaveBalances;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class LeaveBalanceController
{
    private const YEAR = ['sometimes', 'integer', 'between:2000,2100'];

    public function __construct(private readonly LeaveBalances $balances) {}

    public function index(Request $request): JsonResponse
    {
        self::refuseOwnScope($request);
        $v = $request->validate([
            'year' => self::YEAR,
            'type' => ['sometimes', Rule::enum(EmployeeType::class)->only(EmployeeType::monthly())],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $list = $this->balances->list(
            (int) ($v['year'] ?? now('Africa/Cairo')->year), $v['type'] ?? null, $v['q'] ?? null,
            (int) ($v['per_page'] ?? 20), (int) ($v['page'] ?? 1), RequestScope::ownerId($request),
        );

        return ApiEnvelope::collection($request, $list['data'], $list['pagination']);
    }

    public function show(Request $request, string $employee): JsonResponse
    {
        self::refuseOwnScope($request);
        $q = $request->validate(['year' => self::YEAR]);

        return ApiEnvelope::single($request, $this->balances->forEmployee($employee, (int) ($q['year'] ?? now('Africa/Cairo')->year)));
    }

    public function adjust(Request $request, string $employee, string $year): JsonResponse
    {
        $data = Validator::make([...$request->all(), 'year' => $year], [
            'year' => self::YEAR,
            'entitlement' => ['nullable', 'integer', 'between:0,366'],
            'opening_used' => ['required', 'integer', 'between:0,366'],
            'opening_casual_used' => ['required', 'integer', 'min:0', 'lte:opening_used'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'version' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        return ApiEnvelope::single($request, $this->balances->adjust(
            $employee, (int) $year, Arr::only($data, ['entitlement', 'opening_used', 'opening_casual_used', 'reason']), isset($data['version']) ? (int) $data['version'] : null,
        ));
    }

    /**
     * Balances are HR's: a site manager (own scope) reads no one's, their own included (owner, 2026-11-19). They still file leave and
     * read each request's status through leave.view.own.
     */
    private static function refuseOwnScope(Request $request): void
    {
        if (RequestScope::ownerId($request) !== null) {
            throw AuthorizationRefused::of('leave.view');
        }
    }
}
