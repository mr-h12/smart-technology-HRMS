<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Application\Administration\UserAdministration;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class UserController
{
    public function __construct(private readonly UserAdministration $users) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'in:true,false'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $r = $this->users->list($f);

        return ApiEnvelope::collection($request, $r['data'], $r['pagination']);
    }

    public function store(Request $request): JsonResponse
    {
        return ApiEnvelope::single($request, $this->users->create($request->all()), 201);
    }

    public function show(Request $request, string $user): JsonResponse
    {
        return ApiEnvelope::single($request, $this->users->show($user));
    }

    public function update(Request $request, string $user): JsonResponse
    {
        return ApiEnvelope::single($request, $this->users->update($user, $request->all()));
    }

    public function deactivate(Request $request, string $user): JsonResponse
    {
        return ApiEnvelope::single($request, $this->users->setActive($user, false));
    }

    public function reactivate(Request $request, string $user): JsonResponse
    {
        return ApiEnvelope::single($request, $this->users->setActive($user, true));
    }

    public function password(Request $request, string $user): Response
    {
        $request->validate(['password' => ['required', 'string']]);
        $this->users->setPassword($user, $request->string('password')->toString());

        return response()->noContent();
    }

    public function sessions(Request $request, string $user): JsonResponse
    {
        return ApiEnvelope::single($request, $this->users->sessions($user));
    }

    public function terminateSession(string $user, string $session): Response
    {
        $this->users->terminateSession($user, $session);

        return response()->noContent();
    }
}
