<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Presentation;

use App\Modules\Attendance\Application\OfficialHolidays;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class HolidayController
{
    public function __construct(private readonly OfficialHolidays $holidays) {}

    public function index(Request $request): JsonResponse
    {
        $q = $request->validate(['year' => ['sometimes', 'integer', 'between:2000,2100'], 'per_page' => ['sometimes', 'integer', 'min:1'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $r = $this->holidays->list(isset($q['year']) ? (int) $q['year'] : null, (int) ($q['per_page'] ?? 20));

        return ApiEnvelope::collection($request, $r['data'], $r['pagination']);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var array{date: string, name: string} $data */
        $data = $request->validate($this->rules(false));

        return ApiEnvelope::single($request, $this->holidays->create($data), 201);
    }

    public function update(Request $request, string $holiday): JsonResponse
    {
        /** @var array{date?: string, name?: string} $data */
        $data = $request->validate($this->rules(true));

        return ApiEnvelope::single($request, $this->holidays->update($holiday, $data));
    }

    public function destroy(string $holiday): Response
    {
        $this->holidays->delete($holiday);

        return response()->noContent();
    }

    /** @return array<string, list<string>> */
    private function rules(bool $patch): array
    {
        $rules = ['date' => ['required', 'date_format:Y-m-d'], 'name' => ['required', 'string', 'max:128']];

        return $patch ? array_map(fn (array $r) => ['sometimes', ...$r], $rules) : $rules;
    }
}
