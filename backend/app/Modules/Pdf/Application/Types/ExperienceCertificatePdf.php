<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Application\Types;

use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Pdf\Infrastructure\FilePdfAssets;
use App\Support\Localization\ArabicFormDate;
use App\Support\Localization\ArabicServiceDuration;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use stdClass;

final class ExperienceCertificatePdf implements PdfDocument
{
    public function __construct(private readonly EmployeeService $employees) {}

    public static function standardPraise(string $gender): string
    {
        return $gender === 'female'
            ? 'وكانت من أكفأ الموظفات اللاتي عملن بالشركة، وتميزت في أداء مهامها وتمتعت بالتقدير والاحترام والثقة الكاملة من جميع المسؤولين لدى الشركة.'
            : 'وكان من أكفأ الموظفين الذين عملوا بالشركة، وتميز في أداء مهامه وتمتع بالتقدير والاحترام والثقة الكاملة من جميع المسؤولين لدى الشركة.';
    }

    public function rules(): array
    {
        return ['job_title' => ['required', 'string', 'max:160']];
    }

    /** $fields['praise']: null takes the standard praise for the employee's gender, '' prints none. */
    public function snapshot(string $subjectId, array $fields, ?string $scope, bool $showsPay): array
    {
        $employee = $this->employees->show($subjectId);
        foreach (['national_id', 'department', 'gender', 'hire_date'] as $field) {
            if (empty($employee[$field])) {
                throw ValidationException::withMessages([$field => __('errors.certificate_employee_data')]);
            }
        }

        return [$subjectId, [
            ...array_intersect_key($employee, array_flip(['name_ar', 'national_id', 'department', 'gender', 'hire_date', 'termination_date'])),
            ...$fields, 'praise' => $fields['praise'] ?? self::standardPraise($employee['gender']),
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
        $service = ArabicServiceDuration::format($start->diff($end));

        return ['pdf.experience-certificate', [
            'footer' => FilePdfAssets::asset('letterhead/certificate-footer.jpg', 'image/jpeg'),
            'letterhead' => FilePdfAssets::asset('letterhead/certificate-letterhead.png', 'image/png'),
            'issuedOn' => ArabicFormDate::format($snapshot['issued_on']),
            'service' => $service === '' ? 'أقل من يوم' : $service,
            'praise' => $snapshot['praise'] ?? self::standardPraise($snapshot['gender']),
        ]];
    }

    /** Any holder of hr_forms.approve signs; the certificate has no stamp line. */
    public function approval(stdClass $export, Authenticatable $actor): ?string
    {
        return null;
    }
}
