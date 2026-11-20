<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Presentation;

use App\Modules\Attendance\Application\SiteDayCrew;
use App\Modules\Employees\Application\EmployeeRules;
use App\Modules\Employees\Domain\Gender;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Today's day-labor crew on one of the site manager's sites: find, add, remove, and a new day laborer with a proposed wage. */
final class SiteDayCrewController
{
    public function __construct(private readonly SiteDayCrew $crew) {}

    public function search(Request $request): JsonResponse
    {
        // the exact code or the full name, never part of one (owner, 2026-11-05)
        $q = $request->validate(['site_id' => ['required', 'uuid'], 'search' => ['required', 'string', 'max:150']]);

        return ApiEnvelope::single($request, $this->crew->search($q['site_id'], $q['search'], $this->manager($request)));
    }

    public function update(Request $request): JsonResponse
    {
        $q = $request->validate(['site_id' => ['required', 'uuid'], 'employee_id' => ['required', 'uuid'], 'action' => ['required', 'in:add,remove']]);
        $q['action'] === 'add'
            ? $this->crew->add($q['site_id'], $q['employee_id'], $this->manager($request))
            : $this->crew->remove($q['site_id'], $q['employee_id'], $this->manager($request));

        return ApiEnvelope::single($request, ['employee_id' => $q['employee_id'], 'action' => $q['action']]);
    }

    /**
     * A new day laborer (owner, 2026-11-19): a name of at least three words (spaces trimmed and collapsed, and stored that way), an
     * optional 14-digit national ID kept as text, and the form's `client_request_id` so a retry adds nobody. NormalizeDigits has
     * already read Arabic and Persian digits in the wage, phone and national ID.
     */
    public function store(Request $request): JsonResponse
    {
        if (is_string($name = $request->input('name_ar'))) {
            $request->merge(['name_ar' => trim((string) preg_replace('/\s+/u', ' ', $name))]);
        }
        /** @var array{site_id: string, name_ar: string, gender: string, phone?: ?string, national_id?: ?string, daily_wage: string, client_request_id?: ?string, confirm_new?: bool} $data */
        $data = $request->validate([
            'site_id' => ['required', 'uuid'],
            'name_ar' => ['required', 'string', 'max:150', EmployeeRules::THREE_PART_NAME],
            'gender' => ['required', Rule::enum(Gender::class)],
            'phone' => ['nullable', 'string', EmployeeRules::MOBILE],
            'national_id' => ['nullable', 'string', 'digits:14'],
            'daily_wage' => ['required', 'string', EmployeeRules::MONEY, EmployeeRules::POSITIVE],
            'client_request_id' => ['nullable', 'uuid'],
            'confirm_new' => ['sometimes', 'boolean'],
        ], ['name_ar.regex' => __('errors.name_three_parts')]);
        $data['confirm_new'] = $request->boolean('confirm_new');

        return ApiEnvelope::single($request, $this->crew->propose($data['site_id'], $data, $this->manager($request)))->setStatusCode(201);
    }

    private function manager(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
