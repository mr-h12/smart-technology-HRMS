<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Eloquent;

use App\Modules\Audit\Infrastructure\Audited;
use App\Support\Database\HasStandardColumns;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/** A named period of official holiday, from date to end_date (inclusive); live holidays never overlap. */
class OfficialHoliday extends Model
{
    use Audited;
    use HasStandardColumns;

    protected $guarded = [];

    /**
     * Every date from $start to $end that a live holiday covers, in date order; with $changedSince, only holidays created or updated after it.
     *
     * @return list<string> Y-m-d
     */
    public static function datesBetween(string $start, string $end, ?DateTimeInterface $changedSince = null): array
    {
        $holidays = self::where('date', '<=', $end)->where('end_date', '>=', $start)->orderBy('date');
        if ($changedSince !== null) {
            $holidays->where(fn ($q) => $q->where('created_at', '>', $changedSince)->orWhere('updated_at', '>', $changedSince));
        }
        $dates = [];
        foreach ($holidays->get(['date', 'end_date']) as $h) {
            $last = min((string) $h->end_date, $end);
            for ($d = new DateTimeImmutable(max((string) $h->date, $start)); $d->format('Y-m-d') <= $last; $d = $d->modify('+1 day')) {
                $dates[] = $d->format('Y-m-d');
            }
        }

        return $dates;
    }
}
