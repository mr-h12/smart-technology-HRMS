<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Administration;

use App\Modules\Audit\Infrastructure\AuditLog;
use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Domain\Authentication\IdleTimeout;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Identity\Infrastructure\Eloquent\UserSession;
use App\Support\Http\Paginates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use SensitiveParameter;

/** Developer-console accounts. Validates here, not in the controller: the console command is a second entry point. */
final class UserAdministration
{
    use Paginates;

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}}
     */
    public function list(array $filters): array
    {
        $query = User::query()->with('role')->orderBy('name')->orderBy('id');
        if (isset($filters['search']) && $filters['search'] !== '') {
            $like = '%'.addcslashes((string) $filters['search'], '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like));
        }
        if (isset($filters['active'])) {
            $query->where('is_active', $filters['active'] === 'true');
        }

        return $this->page($query, $filters['per_page'] ?? 20);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $v = Validator::make(self::normalised($data), self::rules())->validate();

        return DB::transaction(function () use ($v): array {
            $user = User::create([...$v, 'is_active' => true]);
            AuditLog::record(IdentityAuditEvents::USER_CREATED, 'users', (string) $user->id, null, [
                'name' => $user->name, 'email' => $user->email, 'role_id' => $user->role_id, 'is_active' => true,
            ]);

            return $this->row($user);
        });
    }

    /** @return array<string, mixed> */
    public function show(string $id): array
    {
        return $this->row(User::findOrFail($id));
    }

    /**
     * @param  array<string, mixed>  $data  name, email, role_id
     * @return array<string, mixed>
     */
    public function update(string $id, array $data): array
    {
        $user = User::findOrFail($id);
        $rules = array_map(fn (array $r) => ['sometimes', ...$r], Arr::except(self::rules($id), 'password'));
        $v = Validator::make(self::normalised($data), $rules)->validate();

        return DB::transaction(function () use ($user, $v): array {
            $user->fill($v);
            $changed = $user->getDirty();
            if ($changed !== []) {
                $old = array_intersect_key($user->getOriginal(), $changed);
                $user->save();
                AuditLog::record(IdentityAuditEvents::USER_UPDATED, 'users', (string) $user->id, $old, $changed);
                if (array_key_exists('role_id', $changed)) {
                    RoleAdministration::assertRoleManagerRemains('role_id');
                }
            }

            return $this->row($user);
        });
    }

    /** @return array<string, mixed> */
    public function setActive(string $id, bool $active): array
    {
        return DB::transaction(function () use ($id, $active): array {
            $user = User::findOrFail($id);
            if ($user->is_active === $active) {
                return $this->row($user);
            }
            $user->fill($active ? ['is_active' => true, 'failed_login_attempts' => 0, 'locked_until' => null] : ['is_active' => false]);
            $changed = $user->getDirty();
            $old = array_intersect_key($user->getOriginal(), $changed);
            $user->save();
            AuditLog::record($active ? IdentityAuditEvents::USER_ACTIVATED : IdentityAuditEvents::USER_DEACTIVATED, 'users', (string) $user->id, $old, $changed);
            if (! $active) {
                $user->sessions()->delete();
                RoleAdministration::assertRoleManagerRemains('is_active');
            }

            return $this->row($user);
        });
    }

    public function setPassword(string $id, #[SensitiveParameter] string $password): void
    {
        Validator::make(['password' => $password], Arr::only(self::rules(), 'password'))->validate();

        DB::transaction(function () use ($id, $password): void {
            $user = User::findOrFail($id);
            $user->update(['password' => $password, 'failed_login_attempts' => 0, 'locked_until' => null]);
            $user->sessions()->delete();
            AuditLog::record(IdentityAuditEvents::PASSWORD_SET, 'users', (string) $user->id);
        });
    }

    /** @return list<array<string, mixed>> live sessions, newest activity first */
    public function sessions(string $id): array
    {
        return array_values(User::findOrFail($id)->sessions()
            ->where('last_activity_at', '>=', now()->subHours(IdleTimeout::HOURS))
            ->orderByDesc('last_activity_at')->orderByDesc('id')->get()
            ->map(function (UserSession $s): array {
                $a = $s->toArray();

                return [...Arr::only($a, ['id', 'ip_address', 'user_agent', 'last_activity_at']), 'signed_in_at' => Arr::get($a, 'created_at')];
            })->all());
    }

    public function terminateSession(string $userId, string $sessionId): void
    {
        DB::transaction(function () use ($userId, $sessionId): void {
            UserSession::whereKey($sessionId)->where('user_id', $userId)->firstOrFail()->delete();
            AuditLog::record(IdentityAuditEvents::ADMIN_SESSION_TERMINATED, 'users', $userId, ['session_id' => $sessionId]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function normalised(array $data): array
    {
        // Stored emails are always lowercase, so the unique rule compares like with like.
        if (is_string($data['email'] ?? null)) {
            $data['email'] = mb_strtolower(trim($data['email']));
        }

        return $data;
    }

    /** @return array<string, list<mixed>> */
    private static function rules(?string $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')->ignore($ignoreId)],
            'password' => ['required', 'string', Password::min(8)->letters()->numbers()],
            'role_id' => ['bail', 'required', 'uuid', Rule::exists('roles', 'id')->whereNull('deleted_at')],
        ];
    }

    /** @return array<string, mixed> */
    private function row(Model $m): array
    {
        /** @var User $m */
        $role = $m->role;

        return [
            ...Arr::only($m->toArray(), ['id', 'name', 'email', 'is_active', 'locked_until', 'failed_login_attempts', 'created_at']),
            'role' => $role === null ? null : ['id' => (string) $role->id, 'slug' => $role->slug, 'name' => $role->name, 'name_ar' => $role->name_ar],
        ];
    }
}
