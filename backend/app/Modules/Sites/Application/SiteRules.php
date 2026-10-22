<?php

declare(strict_types=1);

namespace App\Modules\Sites\Application;

use App\Modules\Sites\Domain\AllowanceMode;
use App\Modules\Sites\Domain\LeaveCode;
use App\Modules\Sites\Domain\SiteKind;
use Illuminate\Validation\Rule;

/**
 * The site, residence and fare validation rules, shared by the API and the master-data importer so both accept exactly the same
 * input. Callers normalise `name_ar` with Name::normalise before validating, so the unique rules compare normalised names.
 */
final class SiteRules
{
    private const MONEY = ['regex:/^\d{1,12}(\.\d{1,6})?$/D'];

    /** @return array<string, array<mixed>> */
    public static function site(bool $patch, ?string $id = null): array
    {
        $r = fn (array $rules) => $patch ? ['sometimes', ...$rules] : $rules;

        return [
            'name_ar' => $r(['required', 'string', Rule::unique('sites', 'name_ar')->whereNull('deleted_at')->ignore($id)]),
            'name_en' => ['nullable', 'string'],
            'kind' => $r(['required', Rule::enum(SiteKind::class)]),
            'leave_code' => ['nullable', Rule::enum(LeaveCode::class)],
            'outside_alexandria' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, array<mixed>> */
    public static function allowanceRule(): array
    {
        return [
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'mode' => ['required', Rule::enum(AllowanceMode::class)],
            'value' => ['nullable', ...self::MONEY],
        ];
    }

    /** @return array<string, array<mixed>> */
    public static function residence(bool $patch, ?string $id = null): array
    {
        $rules = ['required', 'string', Rule::unique('residences', 'name_ar')->whereNull('deleted_at')->ignore($id)];

        return ['name_ar' => $patch ? ['sometimes', ...$rules] : $rules];
    }

    /** @return array<string, array<mixed>> */
    public static function fare(): array
    {
        return [
            'residence_id' => ['required', 'uuid', Rule::exists('residences', 'id')->whereNull('deleted_at')],
            ...self::fareOverride(),
        ];
    }

    /** @return array<string, array<mixed>> */
    public static function fareOverride(): array
    {
        return [
            'site_id' => ['required', 'uuid', Rule::exists('sites', 'id')->whereNull('deleted_at')],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', ...self::MONEY],
        ];
    }
}
