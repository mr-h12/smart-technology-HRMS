<?php

declare(strict_types=1);

namespace App\Modules\Leave\Presentation;

use App\Modules\Identity\Presentation\RequestScope;
use App\Modules\Leave\Application\LeaveRequests;
use App\Modules\Leave\Domain\LeaveKind;
use App\Modules\Leave\Domain\LeaveStatus;
use App\Support\Http\ApiEnvelope;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class LeaveRequestController
{
    public function __construct(private readonly LeaveRequests $requests) {}

    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'status' => ['sometimes', Rule::enum(LeaveStatus::class)],
            'employee_id' => ['sometimes', 'uuid'],
            'year' => ['sometimes', 'integer', 'between:2000,2100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $list = $this->requests->list(
            $v['status'] ?? null, $v['employee_id'] ?? null, isset($v['year']) ? (int) $v['year'] : null,
            (int) ($v['per_page'] ?? 20), (int) ($v['page'] ?? 1), RequestScope::ownerId($request),
        );

        return ApiEnvelope::collection($request, $list['data'], $list['pagination']);
    }

    public function preview(Request $request): JsonResponse
    {
        $v = $request->validate(self::range());

        return ApiEnvelope::single($request, $this->requests->preview(
            $v['employee_id'], LeaveKind::from($v['kind']), $v['from_date'], $v['to_date'], RequestScope::ownerId($request),
        ));
    }

    public function store(Request $request): JsonResponse
    {
        /** @var array{employee_id: string, kind: string, from_date: string, to_date: string, note?: ?string} $v */
        $v = $request->validate([...self::range(), 'note' => ['nullable', 'string', 'max:'.LeaveRequests::NOTE_MAX]]);
        $user = $request->user() ?? throw new AuthenticationException;

        return ApiEnvelope::single($request, $this->requests->submit($v, (string) $user->getAuthIdentifier(), RequestScope::ownerId($request)), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiEnvelope::single($request, $this->requests->show($id, RequestScope::ownerId($request)));
    }

    /** approve, refuse (reason required) or escalate (reason optional); LeaveRequests checks the permission the request's status needs. */
    public function decide(Request $request, string $id, string $action): JsonResponse
    {
        $v = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'reason' => match ($action) {
                'refuse' => ['required', 'string', 'max:1000'],
                'escalate' => ['nullable', 'string', 'max:1000'],
                default => ['prohibited'],
            },
            'replace_dates' => $action === 'approve' ? ['sometimes', 'array', 'max:'.LeaveRequests::MAX_RANGE_DAYS] : ['prohibited'],
            'replace_dates.*' => ['date_format:Y-m-d'],
        ]);
        $user = $request->user() ?? throw new AuthenticationException;
        $version = (int) $v['version'];

        return ApiEnvelope::single($request, match ($action) {
            'refuse' => $this->requests->refuse($id, $version, (string) $v['reason'], $user),
            'escalate' => $this->requests->escalate($id, $version, $v['reason'] ?? null, $user),
            default => $this->requests->approve($id, $version, $user, array_values(array_map('strval', $v['replace_dates'] ?? []))),
        });
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $v = $request->validate(['version' => ['required', 'integer', 'min:1'], 'reason' => ['nullable', 'string', 'max:1000']]);
        $user = $request->user() ?? throw new AuthenticationException;

        return ApiEnvelope::single($request, $this->requests->cancel($id, (int) $v['version'], $v['reason'] ?? null, $user, RequestScope::ownerId($request)));
    }

    /** @return array<string, list<mixed>> */
    private static function range(): array
    {
        return [
            'employee_id' => ['required', 'uuid'],
            'kind' => ['required', Rule::enum(LeaveKind::class)],
            'from_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date', 'before_or_equal:2100-12-31'],
        ];
    }
}
