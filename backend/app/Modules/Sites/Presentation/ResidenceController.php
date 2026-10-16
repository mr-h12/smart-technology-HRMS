<?php

declare(strict_types=1);

namespace App\Modules\Sites\Presentation;

use App\Modules\Sites\Application\FareService;
use App\Modules\Sites\Domain\Name;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class ResidenceController
{
    public function __construct(private readonly FareService $fares) {}

    public function index(Request $request): JsonResponse
    {
        $r = $this->fares->listResidences($request->validate(['per_page' => ['sometimes', 'integer', 'min:1']]));

        return ApiEnvelope::collection($request, $r['data'], $r['pagination']);
    }

    public function store(Request $request): JsonResponse
    {
        return ApiEnvelope::single($request, $this->fares->createResidence($this->validated($request, false)), 201);
    }

    public function show(Request $request, string $residence): JsonResponse
    {
        return ApiEnvelope::single($request, $this->fares->showResidence($residence));
    }

    public function update(Request $request, string $residence): JsonResponse
    {
        return ApiEnvelope::single($request, $this->fares->updateResidence($residence, $this->validated($request, true, $residence)));
    }

    public function destroy(string $residence): Response
    {
        $this->fares->deleteResidence($residence);

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $patch, ?string $id = null): array
    {
        if ($request->has('name_ar')) {
            $request->merge(['name_ar' => Name::normalise($request->input('name_ar'))]);
        }
        $rules = ['required', 'string', Rule::unique('residences', 'name_ar')->whereNull('deleted_at')->ignore($id)];

        return $request->validate(['name_ar' => $patch ? ['sometimes', ...$rules] : $rules]);
    }
}
