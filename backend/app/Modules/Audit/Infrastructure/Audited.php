<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure;

use Illuminate\Database\Eloquent\Model;

/** Records CREATED / UPDATED / DELETED for the model, keyed by its table name, in the write's transaction. */
trait Audited
{
    private const AUDIT_SKIPPED = ['created_at', 'updated_at', 'created_by', 'updated_by'];

    public static function bootAudited(): void
    {
        static::created(fn (Model $m) => AuditLog::record('CREATED', $m->getTable(), (string) $m->getKey(), null, self::audited($m->getAttributes())));

        static::updated(function (Model $m): void {
            $new = self::audited($m->getChanges());
            if ($new !== []) {
                AuditLog::record('UPDATED', $m->getTable(), (string) $m->getKey(), array_intersect_key($m->getRawOriginal(), $new), $new);
            }
        });

        static::deleted(fn (Model $m) => AuditLog::record('DELETED', $m->getTable(), (string) $m->getKey(), self::audited($m->getAttributes())));
    }

    /** @param  array<string, mixed>  $options */
    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(fn () => parent::save($options));
    }

    public function delete(): ?bool
    {
        return $this->getConnection()->transaction(fn () => parent::delete());
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function audited(array $values): array
    {
        return array_diff_key($values, array_flip(self::AUDIT_SKIPPED));
    }
}
