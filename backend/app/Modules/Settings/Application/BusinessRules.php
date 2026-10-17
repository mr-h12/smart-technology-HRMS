<?php

declare(strict_types=1);

namespace App\Modules\Settings\Application;

use App\Modules\Settings\Infrastructure\Eloquent\Setting;
use Illuminate\Support\Facades\DB;

/** The business-rule settings, read and written as strings (never floats). */
final class BusinessRules
{
    /** @return array<string, string> */
    public function all(): array
    {
        /** @var array<string, string> */
        return Setting::orderBy('key')->pluck('value', 'key')->all();
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    public function update(array $values): array
    {
        DB::transaction(function () use ($values): void {
            foreach (Setting::whereIn('key', array_keys($values))->get() as $setting) {
                $setting->value = $values[$setting->key];
                $setting->save();
            }
        });

        return $this->all();
    }
}
