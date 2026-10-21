<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/** Model half of the standardColumns() block: UUIDv7 key, soft deletes, created_by/updated_by. */
trait HasStandardColumns
{
    use HasUuids;
    use SoftDeletes;

    public static function bootHasStandardColumns(): void
    {
        static::creating(function (Model $m): void {
            $m->setAttribute('created_by', $m->getAttribute('created_by') ?? self::actor());
            $m->setAttribute('updated_by', $m->getAttribute('updated_by') ?? self::actor());
        });
        // A save with no signed-in user (failed-login counter, session touch) keeps the last editor.
        static::updating(function (Model $m): void {
            if (Auth::hasUser()) {
                $m->setAttribute('updated_by', self::actor());
            }
        });
    }

    private static function actor(): int|string|null
    {
        return Auth::hasUser() ? Auth::id() : null;
    }
}
