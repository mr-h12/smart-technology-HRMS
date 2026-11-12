<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Presentation;

use App\Modules\Attendance\Application\AttendanceDays;
use App\Modules\Attendance\Application\AttendanceGrid;
use App\Modules\Attendance\Application\AttendanceImport;
use App\Modules\Attendance\Application\AttendanceTemplate;
use App\Modules\Attendance\Application\EffectiveDays;
use App\Modules\Attendance\Application\SiteWorkbookImport;
use App\Modules\Attendance\Application\SiteWorkbookTemplate;
use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Modules\Identity\Domain\Rbac\Scope;
use App\Modules\Identity\Presentation\RequestScope;
use App\Modules\Sites\Domain\SiteKind;
use App\Support\Http\ApiEnvelope;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class AttendanceController
{
    /** G9, W16: 5 MB, .xlsx only. The file is read from PHP's temp path and never stored. */
    private const FILE = ['required', 'file', 'max:5120', 'mimes:xlsx', 'extensions:xlsx'];

    public function __construct(
        private readonly AttendanceGrid $grid,
        private readonly EffectiveDays $days,
        private readonly AttendanceDays $writes,
        private readonly EmployeeService $employees,
        private readonly AttendanceTemplate $template,
        private readonly AttendanceImport $import,
        private readonly SiteWorkbookTemplate $workbook,
        private readonly SiteWorkbookImport $workbookImport,
    ) {}

    public function period(Request $request): JsonResponse
    {
        $q = $request->validate([...$this->periodRules(), 'with_archived' => ['sometimes', 'boolean']]);

        return ApiEnvelope::single($request, $this->grid->period(EmployeeType::from($q['group']), $q['period_start'] ?? null, $q['date'] ?? null, RequestScope::ownerId($request), $request->boolean('with_archived'), $request->user()));
    }

    /** `group=all` is every type over the engineers' window. */
    public function grid(Request $request): JsonResponse
    {
        $q = $request->validate([...$this->periodRules(), 'group' => ['required', Rule::in(['all', ...array_column(EmployeeType::cases(), 'value')])], 'per_page' => ['sometimes', 'integer', 'min:1'], 'page' => ['sometimes', 'integer', 'min:1'], 'site_id' => ['sometimes', 'uuid'], 'search' => ['sometimes', 'nullable', 'string', 'max:100'], 'with_archived' => ['sometimes', 'boolean']]);
        $r = $this->grid->grid($q['group'] === 'all' ? null : EmployeeType::from($q['group']), $q['period_start'] ?? null, (int) ($q['per_page'] ?? 20), (int) ($q['page'] ?? 1), $q['date'] ?? null, RequestScope::ownerId($request), $q['site_id'] ?? null, $q['search'] ?? null, $request->boolean('with_archived'), $request->user());

        return ApiEnvelope::collection($request, $r['data'], $r['pagination']);
    }

    public function missing(Request $request): JsonResponse
    {
        $q = $request->validate([...$this->periodRules(), 'per_page' => ['sometimes', 'integer', 'min:1'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $r = $this->grid->missing(EmployeeType::from($q['group']), $q['period_start'] ?? null, (int) ($q['per_page'] ?? 20), $q['date'] ?? null, RequestScope::ownerId($request));

        return ApiEnvelope::collection($request, $r['data'], $r['pagination']);
    }

    public function sheet(Request $request): JsonResponse
    {
        $q = $request->validate(['group' => ['required', Rule::in(['all', ...array_column(EmployeeType::cases(), 'value')])], 'date' => ['required', 'date_format:Y-m-d']]);
        $owner = RequestScope::ownerId($request);

        return ApiEnvelope::single($request, $q['group'] === 'all' ? $this->grid->sheetOfEveryType($q['date'], $owner) : $this->grid->sheet(EmployeeType::from($q['group']), $q['date'], $owner));
    }

    /** One employee's period table: `date` (default today in Cairo) picks the period of the employee's own type then. */
    public function employeePeriod(Request $request, string $employee): JsonResponse
    {
        $q = $request->validate(['date' => ['sometimes', 'date_format:Y-m-d']]);

        return ApiEnvelope::single($request, $this->grid->employeePeriod($employee, $q['date'] ?? null));
    }

    /** The Excel grid template of one period. It shows and later writes days, so it needs view and edit, and the narrower scope wins. */
    public function template(Request $request): BinaryFileResponse
    {
        $q = $request->validate(['group' => ['required', Rule::enum(EmployeeType::class)], 'period_start' => ['required', 'date_format:Y-m-d']]);
        $path = $this->template->file(EmployeeType::from($q['group']), $q['period_start'], RequestScope::narrowestOwnerId($request, 'attendance.view', 'attendance.edit'));

        return response()->download($path, "attendance-{$q['group']}-{$q['period_start']}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
            ->deleteFileAfterSend();
    }

    /** The site workbook (calculations §14 W1): one live, active work site and at most 31 days, future dates allowed. HR only. */
    public function siteWorkbook(Request $request): BinaryFileResponse
    {
        $this->assertAllScope($request);
        $q = $request->validate([
            'site_id' => ['bail', 'required', 'uuid', Rule::exists('sites', 'id')->where('kind', SiteKind::Work->value)->where('active', true)->whereNull('deleted_at')],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['bail', 'required', 'date_format:Y-m-d', 'after_or_equal:from', function (string $attribute, mixed $value, Closure $fail) use ($request): void {
                $from = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query('from'));
                if ($from !== false && $from->diff(new DateTimeImmutable((string) $value))->days >= SiteWorkbookTemplate::MAX_DAYS) {
                    $fail(__('attendance.workbook_max_days', ['max' => SiteWorkbookTemplate::MAX_DAYS]));
                }
            }],
        ]);
        $path = $this->workbook->file($q['site_id'], $q['from'], $q['to']);

        return response()->download($path, "site-workbook-{$q['from']}-{$q['to']}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
            ->deleteFileAfterSend();
    }

    /** The site workbook's preview (calculations §14 W15): every row written, then rolled back; nothing is saved, the upload included. HR only. */
    public function siteWorkbookPreview(Request $request): JsonResponse
    {
        $this->assertAllScope($request);
        /** @var UploadedFile $file */
        $file = $request->validate(['file' => self::FILE])['file'];

        return ApiEnvelope::single($request, $this->workbookImport->preview($file->getPathname(), $this->mayCreateEmployees($request)));
    }

    /** The site workbook's confirm (calculations §14 W13, W15): the same file, the preview's token and a keep or replace per conflict. HR only. */
    public function siteWorkbookConfirm(Request $request): JsonResponse
    {
        $this->assertAllScope($request);
        /** @var array{file: UploadedFile, token: string, choices?: array<string, string>} $q */
        $q = $request->validate(['file' => self::FILE, 'token' => ['required', 'string'], 'choices' => ['sometimes', 'array'], 'choices.*' => ['in:keep,replace']]);

        return ApiEnvelope::single($request, $this->workbookImport->confirm($q['file']->getPathname(), $q['token'], $q['choices'] ?? [], $this->mayCreateEmployees($request)));
    }

    /** The import's preview: the template's rule (P34), view and edit with the narrower scope. Nothing is saved, the upload included. */
    public function preview(Request $request): JsonResponse
    {
        [$g, $start, $path] = $this->upload($request);

        return ApiEnvelope::single($request, $this->import->preview($g, $start, $path, RequestScope::narrowestOwnerId($request, 'attendance.view', 'attendance.edit')));
    }

    /** The same file again: its valid changed cells are written, the refused ones returned. */
    public function confirm(Request $request): JsonResponse
    {
        [$g, $start, $path] = $this->upload($request);

        return ApiEnvelope::single($request, $this->import->confirm($g, $start, $path, RequestScope::narrowestOwnerId($request, 'attendance.view', 'attendance.edit')));
    }

    public function show(Request $request, string $employee, string $date): JsonResponse
    {
        $this->assertDate($date);

        return ApiEnvelope::single($request, $this->days->effectiveDay($employee, $date));
    }

    public function update(Request $request, string $employee, string $date): JsonResponse
    {
        $this->assertDate($date);
        $data = $request->validate(AttendanceDays::FORMAT);

        return ApiEnvelope::single($request, $this->writes->save($employee, $date, $data, RequestScope::ownerId($request)));
    }

    /** An own-scope user reaches only employees in their reach on the date, as `employee.scope` does; 404 so ids are not confirmed. */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate(AttendanceDays::BULK_FORMAT);
        $ownerId = RequestScope::ownerId($request);
        if ($ownerId !== null) {
            foreach ($data['rows'] as $row) {
                if (! $this->employees->isManagedBy($row['employee_id'], $ownerId, $data['date'])) {
                    throw new ModelNotFoundException;
                }
            }
        }

        return ApiEnvelope::single($request, $this->writes->bulk($data['date'], $data['rows'], $ownerId));
    }

    public function destroy(Request $request, string $employee, string $date): JsonResponse
    {
        $this->assertDate($date);
        $q = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return ApiEnvelope::single($request, $this->writes->clear($employee, $date, (int) $q['version']));
    }

    /** @return array{EmployeeType, string, string} */
    private function upload(Request $request): array
    {
        $q = $request->validate([
            'group' => ['required', Rule::enum(EmployeeType::class)],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'file' => self::FILE,
        ]);
        /** @var UploadedFile $file */
        $file = $q['file'];

        return [EmployeeType::from($q['group']), $q['period_start'], $file->getPathname()];
    }

    /**
     * The site workbook writes with no owner, which skips a site manager's reach (spec «Differences» 5), so a caller holding either
     * attendance permission at own scope is refused before anything is read or validated.
     */
    private function assertAllScope(Request $request): void
    {
        if (RequestScope::narrowestOwnerId($request, 'attendance.view', 'attendance.edit') !== null) {
            throw AuthorizationRefused::of('attendance.edit');
        }
    }

    /** Spec «Endpoints and permissions»: a site workbook row creates a new day laborer only for a caller holding employee.create at all scope (W5). */
    private function mayCreateEmployees(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && app(AuthorizeAction::class)->decide($user, 'employee.create')->allows(Scope::All);
    }

    /** @return array<string, array<mixed>> */
    private function periodRules(): array
    {
        return ['group' => ['required', Rule::enum(EmployeeType::class)], 'period_start' => ['sometimes', 'date_format:Y-m-d'], 'date' => ['sometimes', 'date_format:Y-m-d', 'prohibits:period_start']];
    }

    /** The route only checks the shape; 2026-02-30 is refused here. */
    private function assertDate(string $date): void
    {
        Validator::make(['date' => $date], ['date' => ['date_format:Y-m-d']])->validate();
    }
}
