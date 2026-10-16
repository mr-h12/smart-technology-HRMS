<?php

declare(strict_types=1);

namespace App\Modules\Sites\Presentation;

use App\Modules\Sites\Application\FareLookup;
use App\Modules\Sites\Application\FareService;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class FareController
{
    private const MONEY = ['required', 'regex:/^\d+(\.\d{1,6})?$/D'];

    public function __construct(private readonly FareService $fares, private readonly FareLookup $lookup) {}

    public function index(Request $request): JsonResponse
    {
        $r = $this->fares->listFares($request->validate([
            'residence_id' => ['sometimes', 'uuid'],
            'site_id' => ['sometimes', 'uuid'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
        ]));

        return ApiEnvelope::collection($request, $r['data'], $r['pagination']);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'residence_id' => ['required', 'uuid', Rule::exists('residences', 'id')->whereNull('deleted_at')],
            'site_id' => $this->site(),
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'amount' => self::MONEY,
        ]);

        return ApiEnvelope::single($request, $this->fares->addFare($data), 201);
    }

    public function override(Request $request, string $employee): JsonResponse
    {
        $data = $request->validate([
            'site_id' => $this->site(),
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'amount' => self::MONEY,
        ]);

        return ApiEnvelope::single($request, $this->fares->addOverride($employee, $data), 201);
    }

    public function show(Request $request, string $employee): JsonResponse
    {
        $q = $request->validate(['site_id' => ['required', 'uuid'], 'date' => ['sometimes', 'date_format:Y-m-d']]);

        return ApiEnvelope::single($request, $this->lookup->describe($employee, $q['site_id'], $q['date'] ?? now('Africa/Cairo')->toDateString()));
    }

    /** @return array<mixed> */
    private function site(): array
    {
        return ['required', 'uuid', Rule::exists('sites', 'id')->whereNull('deleted_at')];
    }
}
