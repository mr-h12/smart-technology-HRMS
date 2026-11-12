<?php

declare(strict_types=1);

namespace App\Modules\Documents\Application;

final class ManualFormDefinitions
{
    /**
     * @return array<string, array{view: view-string, fields: array<string, array{input: string, rules: list<string>}>}>
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
        ];
    }
}
