<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Application\Administration\RoleAdministration;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class RoleController
{
    public function __construct(private readonly RoleAdministration $roles) {}

    public function index(Request $request): JsonResponse
    {
        return ApiEnvelope::single($request, $this->roles->list());
    }

    public function permissions(Request $request): JsonResponse
    {
        return ApiEnvelope::single($request, $this->roles->permissions());
    }

    public function store(Request $request): JsonResponse
    {
        /** @var array{slug: string, name: string, name_ar: string} $data */
        $data = $request->validate([
            'slug' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,63}$/', Rule::unique('roles')->whereNull('deleted_at')],
            ...self::nameRules(null, 'required'),
        ]);

        return ApiEnvelope::single($request, $this->roles->create($data), 201);
    }

    public function update(Request $request, string $role): JsonResponse
    {
        /** @var array{name?: string, name_ar?: string} $data */
        $data = $request->validate(self::nameRules($role, 'sometimes'));

        return ApiEnvelope::single($request, $this->roles->update($role, $data));
    }

    public function syncPermissions(Request $request, string $role): JsonResponse
    {
        /** @var array{permission_ids: list<string>} $data */
        $data = $request->validate([
            'permission_ids' => ['present', 'array'],
            'permission_ids.*' => ['bail', 'uuid', Rule::exists('permissions', 'id')->whereNull('deleted_at')],
        ]);

        return ApiEnvelope::single($request, $this->roles->syncPermissions($role, $data['permission_ids']));
    }

    /** @return array<string, list<mixed>> */
    private static function nameRules(?string $ignoreId, string $presence): array
    {
        return [
            'name' => [$presence, 'string', 'max:128', Rule::unique('roles')->whereNull('deleted_at')->ignore($ignoreId)],
            'name_ar' => [$presence, 'string', 'max:128', Rule::unique('roles')->whereNull('deleted_at')->ignore($ignoreId)],
        ];
    }
}
