<?php

declare(strict_types=1);

namespace App\Modules\Settings\Presentation;

use App\Modules\Settings\Application\BusinessRules;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SettingController
{
    private const INTEGER = 'regex:/^\d{1,3}$/D';

    private const DECIMAL = 'regex:/^\d{1,6}(\.\d{1,6})?$/D';

    private const TIME = 'date_format:H:i';

    private const DAYS = 'regex:/^(saturday|sunday|monday|tuesday|wednesday|thursday|friday)(,(saturday|sunday|monday|tuesday|wednesday|thursday|friday))*$/D';

    public const RULES = [
        'month_days' => self::INTEGER,
        'day_hours' => self::DECIMAL,
        'overtime_multiplier' => self::DECIMAL,
        'lateness_multiplier_office' => self::DECIMAL,
        'lateness_multiplier_engineer' => self::DECIMAL,
        'lateness_multiplier_permanent_worker' => self::DECIMAL,
        'lateness_multiplier_day_laborer' => self::DECIMAL,
        'office_grace_minutes' => self::INTEGER,
        'office_shift_start' => self::TIME,
        'office_shift_end' => self::TIME,
        'weekly_off_office' => self::DAYS,
        'weekly_off_field' => self::DAYS,
        'leave_days_under_one_year' => self::INTEGER,
        'leave_days_one_year_plus' => self::INTEGER,
        'leave_casual_days' => self::INTEGER,
    ];

    public function __construct(private readonly BusinessRules $rules) {}

    public function index(Request $request): JsonResponse
    {
        return ApiEnvelope::single($request, $this->rules->all());
    }

    public function update(Request $request): JsonResponse
    {
        /** @var array<string, string> $values */
        $values = $request->validate(array_map(fn (string $rule) => ['sometimes', 'string', $rule], self::RULES));

        return ApiEnvelope::single($request, $this->rules->update($values));
    }
}
