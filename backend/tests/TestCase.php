<?php

namespace Tests;

use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    // Runs after the app boots and before RefreshDatabase: never wipe anything but the test database.
    protected function setUpTraits()
    {
        $name = DB::connection()->getDatabaseName();

        if ($name !== 'hrms_test') {
            throw new RuntimeException("Refusing to run tests on database '{$name}': only hrms_test is allowed.");
        }

        return parent::setUpTraits();
    }

    /** An active user with password `secret123` and the role with this slug. */
    protected function makeUser(string $roleSlug = 'developer', array $attributes = []): User
    {
        return User::create([
            'name' => $roleSlug,
            'email' => "{$roleSlug}-".Str::lower(Str::random(8)).'@smart.test',
            'password' => 'secret123',
            'role_id' => Role::where('slug', $roleSlug)->value('id'),
            'is_active' => true,
            ...$attributes,
        ]);
    }

    /** makeUser() signed in on the api guard. */
    protected function signIn(string $roleSlug = 'developer', array $attributes = []): User
    {
        $user = $this->makeUser($roleSlug, $attributes);
        $this->actingAs($user, 'api');

        return $user;
    }
}
