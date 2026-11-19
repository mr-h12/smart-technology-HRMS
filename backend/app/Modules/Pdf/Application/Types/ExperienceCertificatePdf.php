<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Application\Types;

use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Pdf\Domain\ValueSize;
use App\Modules\Pdf\Infrastructure\FilePdfAssets;
use App\Support\Localization\ArabicFormDate;
use App\Support\Localization\ArabicServiceDuration;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

final class ExperienceCertificatePdf implements PdfDocument
{
    /** The record limits of the three values; they pick the size step. */
    public const LIMITS = ['name_ar' => 255, 'department' => 255, 'job_title' => 160];

    /** The fullest value's share of its limit, up to; the value size in pt; the body's line spacing in the Word file's terms. */
    public const SIZES = [[0.25, 15, 2.0], [0.6, 13, 1.75], [1.0, 11, 1.5]];

    public function __construct(private readonly EmployeeService $employees) {}

    public function rules(): array
    {
        return ['job_title' => ['required', 'string', 'max:'.self::LIMITS['job_title']]];
    }

    public function normalise(array $fields): array
    {
        return $fields;
    }

    /** An archived employee gets one too (owner decision 10). */
    public function snapshot(string $subjectId, array $fields, ?string $scope, bool $showsPay): array
    {
        DB::table('employees')->where('id', $subjectId)->whereNull('deleted_at')->lockForUpdate()->first() ?? throw new ModelNotFoundException;
        $employee = $this->employees->show($subjectId);
        foreach (['national_id', 'department', 'gender', 'hire_date'] as $field) {
            if (empty($employee[$field])) {
                throw ValidationException::withMessages([$field => __('errors.certificate_employee_data')]);
            }
        }

        return [$subjectId, [
            ...array_intersect_key($employee, array_flip(['name_ar', 'national_id', 'department', 'gender', 'hire_date', 'termination_date'])),
            ...$fields,
            'issued_on' => now('Africa/Cairo')->toDateString(),
        ]];
    }

    public function subjectColumn(): string
    {
        return 'employee_id';
    }

    public function view(array $snapshot): array
    {
        $issued = Carbon::parse($snapshot['issued_on'], 'Africa/Cairo');
        $start = Carbon::parse($snapshot['hire_date'], 'Africa/Cairo');
        $end = $snapshot['termination_date'] === null ? $issued : Carbon::parse($snapshot['termination_date'], 'Africa/Cairo');
        $lengths = array_map(fn (string $key): int => mb_strlen((string) $snapshot[$key]), array_combine(array_keys(self::LIMITS), array_keys(self::LIMITS)));
        $step = ValueSize::byShare($lengths, self::LIMITS, self::SIZES);
        [, $size, $spacing] = $step;

        return ['pdf.experience-certificate', [
            'title' => 'شهادة خبرة',
            'letterhead' => FilePdfAssets::asset('letterhead/certificate-letterhead.png', 'image/png'),
            'logo' => FilePdfAssets::asset('letterhead/logo.png', 'image/png'),
            'footer' => FilePdfAssets::asset('letterhead/certificate-footer.jpg', 'image/jpeg'),
            'issuedOn' => ArabicFormDate::spaced($snapshot['issued_on']),
            'service' => ArabicServiceDuration::format($start->diff($end)),
            'valueSize' => $size,
            'lineSpacing' => $spacing,
        ]];
    }

    /** Any holder of hr_forms.approve signs; the certificate has no stamp line. */
    public function approval(stdClass $export, Authenticatable $actor, bool $lock): ?string
    {
        return null;
    }

    /** Any holder of hr_forms.approve signs. */
    public function signable(Builder $exports, Authenticatable $actor): Builder
    {
        return $exports;
    }
}
