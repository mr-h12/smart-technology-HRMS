<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Authentication;

use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use LogicException;

/** The signed-in person as GET /auth/me shows them; no credential internals. */
final class Profile
{
    public function __construct(private readonly AuthorizeAction $authorize) {}

    /** @return array{id: string, name: string, email: string, role: array{id: string, slug: string, name: string, name_ar: string}, permissions: list<string>, view_as: array{by: array{id: string, name: string}, expires_at: string}|null} */
    public function for(Authenticatable $user): array
    {
        if (! $user instanceof User) {
            throw new LogicException('The api guard yields only Identity users.');
        }
        $role = $user->role()->firstOrFail();

        return [
            'id' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => ['id' => (string) $role->id, 'slug' => $role->slug, 'name' => $role->name, 'name_ar' => $role->name_ar],
            'permissions' => $this->authorize->permissionsOf($user),
            'view_as' => $user->viewAs === null ? null : [
                'by' => ['id' => $user->viewAs->byId, 'name' => $user->viewAs->byName],
                'expires_at' => Carbon::instance($user->viewAs->expiresAt)->toJSON(),
            ],
        ];
    }

    /** @return array{id: string, name: string, email: string, role: array{id: string, slug: string, name: string, name_ar: string}, permissions: list<string>, view_as: array{by: array{id: string, name: string}, expires_at: string}|null} */
    public function forId(string $userId): array
    {
        return $this->for(User::findOrFail($userId));
    }
}
