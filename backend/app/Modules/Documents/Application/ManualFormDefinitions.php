<?php

declare(strict_types=1);

namespace App\Modules\Documents\Application;

final class ManualFormDefinitions
{
    public static function showsPay(string $form): bool
    {
        return self::all()[$form]['shows_pay'] ?? false;
    }

    /**
     * @return array<string, array{view: view-string, audit_field_names_only?: bool, candidate?: bool, shows_pay?: bool, fields: array<string, array{input: string, rules: list<string>}>}>
     */
    public static function all(): array
    {
        return [
            'late_permit' => [
                'view' => 'hr-forms.late-permit',
                'fields' => [
                    'addressee' => ['input' => 'text', 'rules' => ['required', 'string', 'max:160']],
                    'permit_date' => ['input' => 'date', 'rules' => ['required', 'date_format:Y-m-d']],
                    'start_time' => ['input' => 'time', 'rules' => ['required', 'date_format:H:i']],
                    'end_time' => ['input' => 'time', 'rules' => ['required', 'date_format:H:i', 'after:start_time']],
                    'reason' => ['input' => 'textarea', 'rules' => ['required', 'string', 'max:500']],
                    'job_title' => ['input' => 'text', 'rules' => ['required', 'string', 'max:160']],
                    'responsible_manager' => ['input' => 'text', 'rules' => ['required', 'string', 'max:160']],
                    'issued_on' => ['input' => 'date', 'rules' => ['required', 'date_format:Y-m-d']],
                ],
            ],
            'early_leave_request' => [
                'view' => 'hr-forms.early-leave-request',
                'fields' => [
                    'regular_start' => ['input' => 'time', 'rules' => ['required', 'date_format:H:i']],
                    'regular_end' => ['input' => 'time', 'rules' => ['required', 'date_format:H:i', 'after:regular_start']],
                    'requested_end' => ['input' => 'time', 'rules' => ['required', 'date_format:H:i', 'after:regular_start', 'before:regular_end']],
                    'issued_on' => ['input' => 'date', 'rules' => ['required', 'date_format:Y-m-d']],
                ],
            ],
            'resignation' => [
                'view' => 'hr-forms.resignation',
                'fields' => [
                    'confirm_declarations' => ['input' => 'checkbox', 'rules' => ['required', 'accepted']],
                ],
            ],
            'warning_notice' => [
                'view' => 'hr-forms.warning-notice',
                'audit_field_names_only' => true,
                'fields' => [
                    'job_title' => ['input' => 'text', 'rules' => ['required', 'string', 'max:160']],
                    'warning_date' => ['input' => 'date', 'rules' => ['required', 'date_format:Y-m-d']],
                    'warning_incident' => ['input' => 'textarea', 'rules' => ['required', 'string', 'max:800']],
                    'warning_period' => ['input' => 'textarea', 'rules' => ['required', 'string', 'max:200']],
                    'warning_prior_occurrence' => ['input' => 'textarea', 'rules' => ['required', 'string', 'max:200']],
                    'warning_explanation_request' => ['input' => 'textarea', 'rules' => ['required', 'string', 'max:200']],
                ],
            ],
            'end_of_service' => [
                'view' => 'hr-forms.end-of-service',
                'shows_pay' => true,
                'audit_field_names_only' => true,
                'fields' => [
                    'job_title' => ['input' => 'text', 'rules' => ['required', 'string', 'max:160']],
                    'issued_on' => ['input' => 'date', 'rules' => ['required', 'date_format:Y-m-d']],
                    'last_working_day' => ['input' => 'date', 'rules' => ['required', 'date_format:Y-m-d']],
                    'last_total_wage' => ['input' => 'text', 'rules' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:[.][0-9]{1,2})?$/D']],
                    'wage_month' => ['input' => 'month', 'rules' => ['required', 'date_format:Y-m']],
                    'final_amount' => ['input' => 'text', 'rules' => ['nullable', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:[.][0-9]{1,2})?$/D']],
                    'override_reason' => ['input' => 'textarea', 'rules' => ['nullable', 'required_with:final_amount', 'string', 'max:500']],
                ],
            ],
            'job_offer' => [
                'view' => 'hr-forms.job-offer',
                'candidate' => true,
                'shows_pay' => true,
                'audit_field_names_only' => true,
                'fields' => [
                    'issued_on' => ['input' => 'date', 'rules' => ['required', 'date_format:Y-m-d']],
                    'candidate_name' => ['input' => 'text', 'rules' => ['required', 'string', 'max:160']],
                    'address' => ['input' => 'textarea', 'rules' => ['required', 'string', 'max:300']],
                    'job_title' => ['input' => 'text', 'rules' => ['required', 'string', 'max:160']],
                    'monthly_salary' => ['input' => 'text', 'rules' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:[.][0-9]{1,2})?$/D']],
                    'evaluation_months' => ['input' => 'text', 'rules' => ['required', 'integer', 'min:1', 'max:120']],
                    'reply_deadline' => ['input' => 'date', 'rules' => ['required', 'date_format:Y-m-d', 'after_or_equal:issued_on']],
                    'start_date' => ['input' => 'date', 'rules' => ['required', 'date_format:Y-m-d', 'after_or_equal:reply_deadline']],
                ],
            ],
            'sim_receipt' => [
                'view' => 'hr-forms.sim-receipt',
                'audit_field_names_only' => true,
                'fields' => [
                    'sim_number' => ['input' => 'text', 'rules' => ['required', 'string', 'regex:/^(?:01|[+]201)[0-9]{9}$/D']],
                    'issued_on' => ['input' => 'date', 'rules' => ['required', 'date_format:Y-m-d']],
                ],
            ],
            'mission_permit' => [
                'view' => 'hr-forms.mission-permit',
                'fields' => [
                    'job_title' => ['input' => 'text', 'rules' => ['required', 'string', 'max:160']],
                    'mission_date' => ['input' => 'date', 'rules' => ['required', 'date_format:Y-m-d']],
                    'mission_reason' => ['input' => 'textarea', 'rules' => ['required', 'string', 'max:500']],
                    'department_manager' => ['input' => 'text', 'rules' => ['required', 'string', 'max:160']],
                ],
            ],
            'leave_permit' => [
                'view' => 'hr-forms.leave-permit',
                'fields' => [
                    'addressee' => ['input' => 'text', 'rules' => ['required', 'string', 'max:160']],
                    'permit_date' => ['input' => 'date', 'rules' => ['required', 'date_format:Y-m-d']],
                    'start_time' => ['input' => 'time', 'rules' => ['required', 'date_format:H:i']],
                    'end_time' => ['input' => 'time', 'rules' => ['required', 'date_format:H:i', 'after:start_time']],
                    'reason' => ['input' => 'textarea', 'rules' => ['required', 'string', 'max:500']],
                    'job_title' => ['input' => 'text', 'rules' => ['required', 'string', 'max:160']],
                    'responsible_manager' => ['input' => 'text', 'rules' => ['required', 'string', 'max:160']],
                    'issued_on' => ['input' => 'date', 'rules' => ['required', 'date_format:Y-m-d']],
                ],
            ],
        ];
    }
}
