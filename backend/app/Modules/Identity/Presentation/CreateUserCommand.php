<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Application\Administration\RoleAdministration;
use App\Modules\Identity\Application\Administration\UserAdministration;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/** How the first Developer account is created on a server. */
final class CreateUserCommand extends Command
{
    protected $signature = 'identity:create-user {email} {--name=} {--role=developer}';

    protected $description = 'Create an account; prompts for its password';

    public function handle(UserAdministration $users, RoleAdministration $roles): int
    {
        $all = $roles->list();
        $role = collect($all)->firstWhere('slug', $this->option('role'));
        if ($role === null) {
            $this->error('Unknown role. Roles: '.implode(', ', array_column($all, 'slug')));

            return self::FAILURE;
        }

        try {
            $user = $users->create([
                'name' => $this->option('name') ?? $this->ask('Name'),
                'email' => $this->argument('email'),
                'password' => $this->secret('Password'),
                'role_id' => $role['id'],
            ]);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->error("$field: ".implode(' ', $messages));
            }

            return self::FAILURE;
        }
        $this->info("Created {$user['email']} ({$role['slug']}).");

        return self::SUCCESS;
    }
}
