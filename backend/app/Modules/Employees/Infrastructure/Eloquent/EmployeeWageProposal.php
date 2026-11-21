<?php

declare(strict_types=1);

namespace App\Modules\Employees\Infrastructure\Eloquent;

use App\Modules\Audit\Infrastructure\Audited;
use App\Modules\Employees\Domain\WageProposalKind;
use App\Modules\Employees\Domain\WageProposalStatus;
use App\Support\Database\HasStandardColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A site manager's proposed daily wage for a day laborer they added, or for one who already exists (#79); a pay row exists only once
 * HR approves it.
 *
 * @property string $id
 * @property string $employee_id
 * @property string $daily_wage
 * @property WageProposalStatus $status
 * @property int $version
 * @property string|null $created_by the site manager who proposed it
 * @property Carbon|null $created_at
 * @property Carbon|null $decided_at
 * @property string|null $approved_wage the daily wage HR approved: the proposed one or HR's revision
 * @property string|null $refusal_reason HR's reason, required on every refusal since 2026-11-19
 * @property string|null $client_request_id the site manager's form id, so a retry never adds a second worker or proposal
 * @property WageProposalKind $kind
 * @property string|null $reason the site manager's optional reason (a wage change), which HR reads
 * @property string|null $effective_from the date HR approved a wage change from
 */
class EmployeeWageProposal extends Model
{
    use Audited;
    use HasStandardColumns;

    protected $guarded = [];

    protected $casts = ['version' => 'integer', 'decided_at' => 'datetime', 'status' => WageProposalStatus::class, 'kind' => WageProposalKind::class];

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
