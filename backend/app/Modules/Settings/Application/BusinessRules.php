<?php

declare(strict_types=1);

namespace App\Modules\Settings\Application;

use App\Modules\Settings\Infrastructure\Eloquent\Setting;
use App\Support\Cache\VersionedCache;
use Illuminate\Support\Facades\DB;

/** The business-rule settings, read and written as strings (never floats). */
final class BusinessRules
{
    private const CACHE_KEY = 'settings';

    /** @return array<string, string> */
    public function all(): array
    {
        /** @var array<string, string> */
        return VersionedCache::remember(self::CACHE_KEY, fn () => Setting::orderBy('key')->pluck('value', 'key')->all());
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
        self::forgetCache();

        return $this->all();
    }

    public static function forgetCache(): void
    {
        VersionedCache::invalidate(self::CACHE_KEY);
    }
}
