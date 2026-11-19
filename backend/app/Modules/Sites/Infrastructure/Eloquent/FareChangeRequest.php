<?php

declare(strict_types=1);

namespace App\Modules\Sites\Infrastructure\Eloquent;

use App\Modules\Audit\Infrastructure\Audited;
use App\Modules\Sites\Domain\FareRequestStatus;
use App\Support\Database\HasStandardColumns;
use App\Support\Database\Precision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A site manager's request to change one employee's daily transport fare at one site; the fare in force holds until HR approves.
 *
 * @property string $id
 * @property string $employee_id
 * @property string $site_id
 * @property string|null $current_amount the fare in force when asked; null when none was set
 * @property string $requested_amount
 * @property string $reason
 * @property FareRequestStatus $status
 * @property string|null $approved_amount the requested amount or HR's revision, once approved
 * @property string|null $effective_from the date the approved amount starts (HR's pick)
 * @property string|null $refusal_reason
 * @property string|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $client_request_id the site manager's form id, so a double tap or retry adds no second request
 * @property int $version
 * @property string|null $created_by the site manager who asked
 * @property Carbon|null $created_at
 */
class FareChangeRequest extends Model
{
    use Audited;
    use HasStandardColumns;

    protected $guarded = [];

    protected $casts = [
        'version' => 'integer', 'decided_at' => 'datetime', 'status' => FareRequestStatus::class,
        'current_amount' => Precision::CAST_MONEY, 'requested_amount' => Precision::CAST_MONEY, 'approved_amount' => Precision::CAST_MONEY,
    ];
}
