<?php

declare(strict_types=1);

namespace App\Modules\Sites\Presentation;

use App\Modules\Identity\Presentation\RequestScope;
use App\Modules\Sites\Application\SiteService;
use App\Modules\Sites\Domain\AllowanceMode;
use App\Modules\Sites\Domain\LeaveCode;
use App\Modules\Sites\Domain\Name;
use App\Modules\Sites\Domain\SiteKind;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class SiteController
{
    public function __construct(private readonly SiteService $sites) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'kind' => ['sometimes', Rule::enum(SiteKind::class)],
            'active' => ['sometimes', 'in:true,false,1,0'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $r = $this->sites->list($f, RequestScope::ownerId($request));

        return ApiEnvelope::collection($request, $r['data'], $r['pagination']);
    }

    public function store(Request $request): JsonResponse
    {
        return ApiEnvelope::single($request, $this->sites->create($this->validated($request, false)), 201);
    }

    public function show(Request $request, string $site): JsonResponse
    {
        return ApiEnvelope::single($request, $this->sites->show($site, RequestScope::ownerId($request)));
    }

    public function update(Request $request, string $site): JsonResponse
    {
        return ApiEnvelope::single($request, $this->sites->update($site, $this->validated($request, true, $site)));
    }

    public function destroy(string $site): Response
    {
        $this->sites->delete($site);

        return response()->noContent();
    }

    public function allowanceRule(Request $request, string $site): JsonResponse
    {
        $data = $request->validate([
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'mode' => ['required', Rule::enum(AllowanceMode::class)],
            'value' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,6})?$/D'],
        ]);

        return ApiEnvelope::single($request, $this->sites->addRule($site, $data), 201);
    }

    public function managers(Request $request, string $site): JsonResponse
    {
        $data = $request->validate([
            'manager_ids' => ['present', 'array'],
            'manager_ids.*' => ['uuid', Rule::exists('users', 'id')->where('is_active', true)->whereNull('deleted_at')],
        ]);

        return ApiEnvelope::single($request, $this->sites->setManagers($site, $data['manager_ids']));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $patch, ?string $id = null): array
    {
        if ($request->has('name_ar')) {
            $request->merge(['name_ar' => Name::normalise($request->input('name_ar'))]);
        }
        $r = fn (array $rules) => $patch ? ['sometimes', ...$rules] : $rules;

        return $request->validate([
            'name_ar' => $r(['required', 'string', Rule::unique('sites', 'name_ar')->whereNull('deleted_at')->ignore($id)]),
            'name_en' => ['nullable', 'string'],
            'kind' => $r(['required', Rule::enum(SiteKind::class)]),
            'leave_code' => ['nullable', Rule::enum(LeaveCode::class)],
            'outside_alexandria' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }
}
