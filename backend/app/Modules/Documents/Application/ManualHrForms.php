<?php

declare(strict_types=1);

namespace App\Modules\Documents\Application;

use App\Modules\Audit\Infrastructure\AuditLog;
use App\Modules\Employees\Application\EmployeeService;
use App\Support\Database\ConcurrencyConflict;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

final class ManualHrForms
{
    public function __construct(private readonly ExperienceCertificates $exports, private readonly EmployeeService $employees) {}

    /** @param array<string, mixed> $fields */
    public function create(string $form, ?string $employeeId, string $actor, string $key, array $fields, ?string $scope): stdClass
    {
        if ($form === 'sim_receipt' && is_string($fields['sim_number'] ?? null) && str_starts_with($fields['sim_number'], '+20')) {
            $fields['sim_number'] = '0'.substr($fields['sim_number'], 3);
        }
        if ($form === 'job_offer') {
            $salary = (string) $fields['monthly_salary'];
            if (! is_numeric($salary) || bccomp($salary, '0', 2) <= 0) {
                throw ValidationException::withMessages(['monthly_salary' => __('validation.gt.numeric', ['attribute' => __('validation.attributes.monthly_salary'), 'value' => '0'])]);
            }
            $fields['monthly_salary'] = bcadd($salary, '0', 2);
            $fields['evaluation_months'] = (int) $fields['evaluation_months'];
        }
        if ($form === 'end_of_service') {
            $today = now('Africa/Cairo')->toDateString();
            if ((string) $fields['issued_on'] > $today) {
                throw ValidationException::withMessages(['issued_on' => __('validation.before_or_equal', [
                    'attribute' => __('validation.attributes.issued_on'), 'date' => $today,
                ])]);
            }
            $wage = (string) $fields['last_total_wage'];
            $fields['last_total_wage'] = is_numeric($wage) ? bcadd($wage, '0', 6) : $wage;
            $amount = $fields['final_amount'] ?? null;
            $fields['final_amount'] = is_string($amount) && is_numeric($amount) ? bcadd($amount, '0', 6) : null;
            $fields['override_reason'] = $fields['final_amount'] === null ? null : ($fields['override_reason'] ?? null);
        }
        ksort($fields);

        return DB::transaction(function () use ($form, $employeeId, $actor, $key, $fields, $scope): stdClass {
            DB::table('users')->where('id', $actor)->lockForUpdate()->firstOrFail();
            $employee = $employeeId === null ? [] : $this->employees->show($employeeId, $scope);
            $existing = DB::table('hr_form_exports')->where(['requested_by' => $actor, 'idempotency_key' => $key])->first();
            if ($existing !== null) {
                $snapshot = json_decode((string) $existing->snapshot, true, flags: JSON_THROW_ON_ERROR);
                $storedFields = array_intersect_key($snapshot['fields'] ?? [], $fields);
                if ($form === 'end_of_service') {
                    $storedFields['final_amount'] = $snapshot['fields']['submitted_amount'] ?? null;
                }
                ksort($storedFields);
                if ($existing->form_type !== $form || $existing->employee_id !== $employeeId || $existing->stage !== 'unsigned'
                    || $storedFields !== $fields) {
                    throw new ConcurrencyConflict;
                }

                return $existing;
            }

            $snapshotFields = $form === 'end_of_service' ? EndOfServiceAmounts::calculate($employee, $fields) : $fields;
            $export = $this->exports->storeUnsigned($form, $employeeId, $actor, $key, [
                ...array_intersect_key($employee, array_flip(['name_ar', 'name_en', 'code', 'department', 'gender'])),
                'fields' => $snapshotFields,
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
