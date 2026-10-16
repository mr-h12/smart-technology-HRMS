<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $email = 'hr@smart.test', string $password = 'secret123'): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    public function test_login_issues_a_bearer_token_and_me_returns_the_profile(): void
    {
        $u = $this->makeUser('hr', ['email' => 'hr@smart.test', 'name' => 'منى']);
        $token = $this->login()->assertCreated()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.idle_timeout_seconds', 28800)
            ->assertJsonPath('data.user.role.slug', 'hr')
            ->assertJsonMissingPath('data.user.password')
            ->json('data.token');
        $this->assertDatabaseHas('user_sessions', ['user_id' => $u->id, 'session_id' => hash('sha256', $token)]);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.name', 'منى');
    }

    public function test_login_ignores_email_case_and_spaces(): void
    {
        $this->makeUser('hr', ['email' => 'hr@smart.test']);
        $this->login(' HR@Smart.Test ')->assertCreated();
    }

    public function test_wrong_password_and_unknown_email_are_401(): void
    {
        $u = $this->makeUser('hr', ['email' => 'hr@smart.test']);
        $this->login(password: 'nope')->assertStatus(401)->assertJsonPath('error.code', 'invalid_credentials');
        $this->assertSame(1, $u->fresh()->failed_login_attempts);
        $this->login('nobody@smart.test')->assertStatus(401)->assertJsonPath('error.code', 'invalid_credentials');
    }

    public function test_fifth_failure_locks_for_the_configured_minutes(): void
    {
        $this->travelTo('2026-10-17 10:00:00');
        $u = $this->makeUser('hr', ['email' => 'hr@smart.test']);
        foreach (range(1, 4) as $_) {
            $this->login(password: 'nope')->assertStatus(401);
        }
        $this->login(password: 'nope')->assertStatus(423)->assertJsonPath('error.code', 'account_locked');
        $this->assertSame('2026-10-17 10:30:00', $u->fresh()->locked_until->utc()->format('Y-m-d H:i:s'));
        $this->login()->assertStatus(423);              // right password, still locked
        $this->travelTo('2026-10-17 10:31:00');
        $this->login()->assertCreated();
        $this->assertSame(0, $u->fresh()->failed_login_attempts);
        $this->assertNull($u->fresh()->locked_until);
    }

    public function test_inactive_account_is_403(): void
    {
        $this->makeUser('hr', ['email' => 'hr@smart.test', 'is_active' => false]);
        $this->login()->assertStatus(403)->assertJsonPath('error.code', 'account_suspended');
    }

    public function test_logout_revokes_the_token(): void
    {
        $this->makeUser('hr', ['email' => 'hr@smart.test']);
        $token = $this->login()->json('data.token');
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_idle_session_expires_after_8_hours_and_activity_extends_it(): void
    {
        $this->travelTo('2026-10-17 08:00:00');
        $this->makeUser('hr', ['email' => 'hr@smart.test']);
        $token = $this->login()->json('data.token');
        $this->travelTo('2026-10-17 15:00:00');
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->travelTo('2026-10-17 22:00:00');
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->travelTo('2026-10-18 06:00:01');
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->assertSoftDeleted('user_sessions', ['session_id' => hash('sha256', $token)]);
    }

    public function test_deactivated_user_token_stops_working(): void
    {
        $u = $this->makeUser('hr', ['email' => 'hr@smart.test']);
        $token = $this->login()->json('data.token');
        $u->update(['is_active' => false]);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_malformed_bearer_is_401_not_500(): void
    {
        foreach (['Bearer abc', 'Bearer '.str_repeat('A', 64), 'Bearer ', 'Basic dXNlcjpwYXNz'] as $header) {
            $this->withHeader('Authorization', $header)->getJson('/api/v1/auth/me')->assertStatus(401);
        }
    }

    public function test_login_is_rate_limited(): void
    {
        $this->makeUser('hr', ['email' => 'hr@smart.test']);
        foreach (range(1, 10) as $_) {
            $this->login(password: 'nope');
        }
        $this->login()->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    }

    public function test_unauthenticated_api_call_without_accept_header_is_401_json(): void
    {
        $this->get('/api/v1/auth/me')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    }
}
