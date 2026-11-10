<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Presentation;

use App\Modules\Attendance\Application\OfficialHolidays;
use App\Support\Http\ApiEnvelope;
use Closure;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class HolidayController
{
    /** The longest holiday: a guard against a mistyped date, not a business setting (owner, 2026-11-10). */
    private const MAX_DAYS = 31;

    public function __construct(private readonly OfficialHolidays $holidays) {}

    public function index(Request $request): JsonResponse
    {
        $q = $request->validate(['year' => ['sometimes', 'integer', 'between:2000,2100'], 'per_page' => ['sometimes', 'integer', 'min:1'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $r = $this->holidays->list(isset($q['year']) ? (int) $q['year'] : null, (int) ($q['per_page'] ?? 20));

        return ApiEnvelope::collection($request, $r['data'], $r['pagination']);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var array{date: string, end_date?: ?string, name: string} $data */
        $data = $request->validate($this->rules($request, false));

        return ApiEnvelope::single($request, $this->holidays->create($data), 201);
    }

    public function update(Request $request, string $holiday): JsonResponse
    {
        /** @var array{date?: string, end_date?: string, name?: string} $data */
        $data = $request->validate($this->rules($request, true));

        return ApiEnvelope::single($request, $this->holidays->update($holiday, $data));
    }

    public function destroy(string $holiday): Response
    {
        $this->holidays->delete($holiday);

        return response()->noContent();
    }

    /**
     * POST: end_date is optional (the one day). PATCH: date and end_date come together, or neither (a rename).
     *
     * @return array<string, list<mixed>>
     */
    private function rules(Request $request, bool $patch): array
    {
        $endDate = ['date_format:Y-m-d', 'after_or_equal:date', function (string $attribute, mixed $value, Closure $fail) use ($request): void {
            $from = $request->input('date');
            $first = is_string($from) ? DateTimeImmutable::createFromFormat('!Y-m-d', $from) : false;
            if ($first !== false && is_string($value) && $value > $first->modify('+'.(self::MAX_DAYS - 1).' days')->format('Y-m-d')) {
                $fail(__('errors.holiday_too_long', ['max' => self::MAX_DAYS]));
            }
        }];

        return $patch
            ? ['date' => ['required_with:end_date', 'date_format:Y-m-d'], 'end_date' => ['required_with:date', ...$endDate], 'name' => ['sometimes', 'required', 'string', 'max:128']]
            : ['date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['nullable', ...$endDate], 'name' => ['required', 'string', 'max:128']];
    }
}
