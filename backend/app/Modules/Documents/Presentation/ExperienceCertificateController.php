<?php

declare(strict_types=1);

namespace App\Modules\Documents\Presentation;

use App\Modules\Documents\Application\AdvanceRequestForms;
use App\Modules\Documents\Application\ExperienceCertificates;
use App\Modules\Documents\Application\RenderHrForm;
use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Identity\Presentation\RequestScope;
use App\Support\Http\ApiEnvelope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExperienceCertificateController
{
    public function __construct(private readonly ExperienceCertificates $certificates, private readonly EmployeeService $employees, private readonly AdvanceRequestForms $advances) {}

    public function defaults(Request $request, string $employee): JsonResponse
    {
        $record = $this->employees->show($employee, RequestScope::ownerId($request));

        return ApiEnvelope::single($request, ['praise' => ExperienceCertificates::standardPraise($record['gender'])]);
    }

    public function store(Request $request, string $employee): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'job_title' => ['required', 'string', 'max:160'],
            'praise' => ['sometimes', 'nullable', 'string', 'max:360'],
            'regenerated_from_id' => ['nullable', 'uuid'],
        ]);
        $export = $this->certificates->create($employee, (string) $request->user()?->getAuthIdentifier(),
            $data['idempotency_key'], $data['job_title'], $data['regenerated_from_id'] ?? null,
            array_key_exists('praise', $data) ? ($data['praise'] ?? '') : null);

        return ApiEnvelope::single($request, self::queue($export), 202);
    }

    public function show(Request $request, string $export): JsonResponse
    {
        return ApiEnvelope::single($request, self::metadata($this->scoped($request, $export)));
    }

    public function history(Request $request, string $employee): JsonResponse
    {
        $this->employees->show($employee, RequestScope::ownerId($request));

        return self::list($request, $this->certificates->history($employee, $request->query('form_type') === 'experience_certificate' ? 'experience_certificate' : null, $this->advances->canViewPay($request->user())));
    }

    public function awaitingApproval(Request $request): JsonResponse
    {
        abort_if(RequestScope::ownerId($request) !== null, 403);

        return self::list($request, $this->certificates->awaitingApproval());
    }

    public function download(Request $request, string $export): StreamedResponse
    {
        $row = $this->scoped($request, $export);
        $disk = Storage::disk('local');
        $path = (string) $row->storage_path;
        abort_unless($row->status === 'ready' && $disk->exists($path), 404);

        return $disk->download($path, str_replace('_', '-', (string) $row->form_type)."-{$row->serial_number}-{$row->stage}.pdf", [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @param LengthAwarePaginator<int, stdClass> $rows */
    private static function list(Request $request, LengthAwarePaginator $rows): JsonResponse
    {
        return ApiEnvelope::collection($request, array_values(array_map(self::metadata(...), $rows->items())), [
            'page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(),
            'total_pages' => $rows->lastPage(), 'has_next_page' => $rows->hasMorePages(),
            'has_previous_page' => $rows->currentPage() > 1,
        ]);
    }

    public function approve(Request $request, string $export): JsonResponse
    {
        $this->scoped($request, $export);
        $signed = $this->certificates->approve($export, (string) $request->user()?->getAuthIdentifier());

        return ApiEnvelope::single($request, self::queue($signed), 202);
    }

    public function retry(Request $request, string $export): JsonResponse
    {
        $this->scoped($request, $export);

        return ApiEnvelope::single($request, self::queue($this->certificates->retry($export)), 202);
    }

    /** @return array<string, mixed> */
    public static function queue(stdClass $row): array
    {
        if ($row->status === 'pending') {
            RenderHrForm::dispatch((string) $row->id);
        }

        return self::metadata($row);
    }

    private function scoped(Request $request, string $export): stdClass
    {
        $row = $this->certificates->find($export);
        abort_if($row->form_type === AdvanceRequestForms::FORM && ! $this->advances->canViewPay($request->user()), 403);
        $scope = RequestScope::ownerId($request);
        abort_if($scope !== null && ($row->employee_id === null || ! $this->employees->isManagedBy((string) $row->employee_id, $scope)), 404);

        return $row;
    }

    /** @return array<string, mixed> */
    private static function metadata(stdClass $row): array
    {
        return array_intersect_key((array) $row, array_flip([
            'id', 'employee_id', 'form_type', 'serial_number', 'stage', 'status',
            'unsigned_export_id', 'regenerated_from_id', 'approved_by', 'approved_at', 'impersonator_id',
            'failure_code', 'sha256', 'size_bytes', 'created_at',
        ]));
    }
}
