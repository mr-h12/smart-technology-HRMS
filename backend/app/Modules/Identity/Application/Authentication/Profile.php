<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Authentication;

use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;

/** The signed-in person as GET /auth/me shows them; no credential internals. */
final class Profile
{
    public function __construct(private readonly AuthorizeAction $authorize) {}

    /** @return array{id: string, name: string, email: string, role: array{id: string, slug: string, name: string, name_ar: string}, permissions: list<string>} */
    public function for(string $userId): array
    {
        $user = User::findOrFail($userId);
        $role = $user->role()->firstOrFail();

        return [
            'id' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => ['id' => (string) $role->id, 'slug' => $role->slug, 'name' => $role->name, 'name_ar' => $role->name_ar],
            'permissions' => $this->authorize->permissionsOf($userId),
        ];
    }
}
