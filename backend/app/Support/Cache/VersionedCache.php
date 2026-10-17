<?php

declare(strict_types=1);

namespace App\Support\Cache;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A forever cache that a write invalidates by moving to a new version key.
 * A reader that loaded the old rows before the write committed can only fill the old version's key, which nobody reads again.
 */
final class VersionedCache
{
    /**
     * @template T
     *
     * @param  Closure(): T  $load
     * @return T
     */
    public static function remember(string $key, Closure $load): mixed
    {
        $version = Cache::get("{$key}:version", 0);

        return Cache::rememberForever("{$key}:{$version}", $load);
    }

    /** Takes effect after the open transaction commits, or at once when none is open. */
    public static function invalidate(string $key): void
    {
        DB::afterCommit(fn () => Cache::increment("{$key}:version"));
    }
}
