<?php

declare(strict_types=1);

namespace App\Modules\Documents\Application;

use App\Modules\Audit\Infrastructure\AuditLog;
use App\Modules\Employees\Application\EmployeeService;
use App\Support\Database\ConcurrencyConflict;
use Illuminate\Support\Facades\DB;
use stdClass;

final class ManualHrForms
{
    public function __construct(private readonly ExperienceCertificates $exports, private readonly EmployeeService $employees) {}

    /** @param array<string, mixed> $fields */
    public function create(string $form, string $employeeId, string $actor, string $key, array $fields, ?string $scope): stdClass
    {
        if ($form === 'sim_receipt' && is_string($fields['sim_number'] ?? null) && str_starts_with($fields['sim_number'], '+20')) {
            $fields['sim_number'] = '0'.substr($fields['sim_number'], 3);
        }
        ksort($fields);

        return DB::transaction(function () use ($form, $employeeId, $actor, $key, $fields, $scope): stdClass {
            DB::table('users')->where('id', $actor)->lockForUpdate()->firstOrFail();
            $employee = $this->employees->show($employeeId, $scope);
            $existing = DB::table('hr_form_exports')->where(['requested_by' => $actor, 'idempotency_key' => $key])->first();
            if ($existing !== null) {
                $snapshot = json_decode((string) $existing->snapshot, true, flags: JSON_THROW_ON_ERROR);
                $storedFields = $snapshot['fields'] ?? [];
                ksort($storedFields);
                if ($existing->form_type !== $form || $existing->employee_id !== $employeeId || $existing->stage !== 'unsigned'
                    || $storedFields !== $fields) {
                    throw new ConcurrencyConflict;
                }

                return $existing;
            }

            $export = $this->exports->storeUnsigned($form, $employeeId, $actor, $key, [
                ...array_intersect_key($employee, array_flip(['name_ar', 'name_en', 'code', 'department'])),
                'fields' => $fields,
            ], null);
            $namesOnly = ManualFormDefinitions::all()[$form]['audit_field_names_only'] ?? false;
            $auditFields = $namesOnly ? ['field_names' => array_keys($fields)] : ['fields' => $fields];
            AuditLog::record('CREATED', 'hr_form_exports', (string) $export->id, null, [
                'form_type' => $form, 'serial_number' => $export->serial_number, ...$auditFields,
            ]);

            return $export;
        });
    }
}
