# Release 1b: Accounts, RBAC, Audit Log and Manager Assignments Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Every implementer and reviewer loads `ponytail:ponytail`, `andrej-karpathy-skills:karpathy-guidelines` and `superpowers:test-driven-development` first.

**Goal:** Accounts with bearer-session login and lockout, data-driven roles and permissions, an append-only audit log on every write, and dated manager ↔ employee plus undated manager ↔ site assignment that scopes what a Manager sees, with a login screen and developer console in the frontend.

**Architecture:** Two new backend modules mirror the CRM: `Identity` (users, sessions, roles, permissions, administration) and `Audit` (one append-only `audit_log` table, written by an `Audited` model trait and by explicit Identity events). Routes are guarded by `auth` (a `viaRequest` bearer guard) plus `permission:resource.action`; the scope a caller holds (`own` | `all`) decides which employees and sites a list or per-employee endpoint returns. As in 1a, Application classes use Eloquent models directly: no repository interfaces.

**Tech Stack:** Laravel 13 / PHP 8.4, PostgreSQL 17.5, PHPUnit 12, Pint, Larastan level 8, deptrac; React 19 + Vite + TS, react-i18next, react-router 7, Vitest + Testing Library.

**Spec:** `CLAUDE.md` ("Users and permissions", "Invariants"), `CONTEXT.md`, `docs/hrms-module-plan.md` Release 1 items 1, 2, 3 (placement: manager), 4 (manager ↔ sites) and "Settled decisions". Format and module precedent: `docs/superpowers/plans/release-1a-foundation.md`.
CRM reference clone (read-only, never run): `/private/tmp/claude-501/-Users-yousefhasabo-HRMS-Smart-TECH/e305f12b-0482-4c03-9bd7-c5daa690b35e/scratchpad/crm-ref/crm`. Copy shapes and names from `app/Modules/Identity`, `app/Modules/Audit`, `app/Http/Middleware/{ForgetResolvedGuards,SetLocaleFromRequest}.php`, `config/identity.php`, `lang/ar/validation.php`; trim every docblock to one or two lines.

**Not in 1b** (one line each):
- Error and request/response log screen: later release.
- Retention jobs (audit 1 year, request logs 90 days): later task.
- Notifications (instant + daily digest): later 1x plan.
- Manager attendance: later 1x plan.
- Leave: later 1x plan (blocked on leave-pay answers).
- Excel migration: later 1x plan.
- Dropped from the CRM: self-service password change, password challenges / verification codes, impersonation, lockout e-mail, `is_hidden` users, role archiving, the `Gate::before` registrar (no code calls `can()`).

## Global Constraints

- No PHP on the host. Backend commands run from the repo root as `docker compose exec app …`. Frontend commands run on the host in `frontend/`.
- Gates every task leaves green (run them all before each commit):
  - `docker compose exec app php artisan test`
  - `docker compose exec app vendor/bin/pint --test`
  - `docker compose exec app vendor/bin/phpstan analyse`
  - `docker compose exec app vendor/bin/deptrac analyse --config-file=deptrac.layers.yaml`
  - `cd frontend && npm test && npm run build && npm run lint`
- PHPUnit 12 feature tests on PostgreSQL `hrms_test` (`tests/TestCase.php` refuses any other DB). TDD: the failing test is written and seen failing before the code.
- Module layout `backend/app/Modules/<Module>/{Domain,Application,Infrastructure,Presentation}`; Domain imports nothing from `Illuminate\*`; Eloquent models in `Infrastructure/Eloquent/`; Application may use Infrastructure directly; Presentation may not touch Infrastructure (deptrac).
- API under `/api/v1`, every response through `App\Support\Http\ApiEnvelope`; lists use the pagination block; route ids `whereUuid`.
- Every new business table uses `standardColumns()` (UUIDv7 id, `created_by`/`updated_by`, `timestampsTz`, `softDeletesTz`) unless the task says otherwise.
- Verbatim from `CLAUDE.md`:
  - "Accounts: Developer, CEO, HR, Manager (5 managers today). **Employees and workers have no accounts.**"
  - "A manager acts on behalf of their own employees: field attendance, leave, requests, the "signed" mark. A manager sees only employees assigned to them. Each employee has exactly one manager at a time (office staff: their department manager); reassignment is dated."
  - "Only the Developer role creates accounts and sets or changes passwords. There is no self-service password reset or change."
  - "Roles and permissions are data (CRM-style `resource.action.scope`), editable from the developer console. Check permissions by name, never by role name."
  - "Money: PostgreSQL `NUMERIC(18,6)` with bcmath decimal strings (as the CRM's `Precision`/`Decimal`). Never use floats. Do not round intermediate values (the Excel doesn't); display 2 decimals."
  - "Time zone `Africa/Cairo`; store UTC."
  - "Every create/update/delete, approval, override, login and period lock/reopen writes an audit-log entry with before/after values."
  - "Every UI string goes through i18n keys in both Arabic (RTL, default) and English."
- "Today" is `now('Africa/Cairo')->toDateString()`. Effective-dated rule: the row in force on D is the greatest `effective_from <= D`.
- Read the acting user as `Auth::hasUser() ? Auth::id() : null`, never bare `Auth::id()`: a model save inside `BearerSessionResolver` would re-enter the guard and recurse.
- Use Carbon `now()` for every clock read (never `new DateTimeImmutable('now')`) so tests can `travelTo`.
- In feature tests, `withToken()` headers persist for the rest of the test, and a bearer request makes `ForgetResolvedGuards` drop an `actingAs` user. After probing a token, call `flushHeaders()` and `actingAs()` again before the next signed-in call.
- Ponytail: no speculative abstraction, fewest files, comments only for a non-obvious why.
- Commit per task on `feature/release-1b-accounts`, message ending with `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.

## Review Focus

1. Email typed with capitals or spaces (`" HR@Smart.Test "`) at login and at account creation: matches the stored lowercase email; a case-variant duplicate is rejected. Tests: Task 1 `test_login_ignores_email_case_and_spaces`, Task 4 `test_create_normalises_email_and_rejects_case_duplicate`.
2. A token used after logout, deactivation, a password reset or a session revoke: 401 on the very next request in the same process (the request guard memoises its user). Tests: Task 1 `test_logout_revokes_the_token`, Task 4 `test_deactivate_and_password_set_end_sessions`.
3. A malformed `Authorization` header (`Bearer abc`, 64 uppercase hex, `Bearer ` empty, `Basic …`): 401 envelope, never 500. Test: Task 1 `test_malformed_bearer_is_401_not_500`.
4. The Developer locking everyone out of the console (removing `role.manage.all` from the last role that holds it, deactivating or re-roling the last active holder): refused with 422. Test: Task 4 `test_cannot_remove_the_last_role_manager`.
5. A reassignment effective tomorrow, seen just after Cairo midnight while UTC is still on the previous day: the new manager sees the employee, the old one does not. Test: Task 5 `test_reassignment_follows_the_cairo_date`.

---

### Task 1: Accounts, bearer sessions, login and lockout

**Files:**
- Create: `backend/database/migrations/2026_10_18_000001_create_identity_tables.php`
- Create: `backend/app/Modules/Identity/Domain/Authentication/{SessionToken,LockoutPolicy,IdleTimeout,RefusalReason,AuthenticationRefused,SessionAttribute}.php`
- Create: `backend/app/Modules/Identity/Application/Authentication/{AuthenticateUser,SignOut,Profile}.php`
- Create: `backend/app/Modules/Identity/Infrastructure/Eloquent/{User,Role,UserSession}.php`
- Create: `backend/app/Modules/Identity/Infrastructure/BearerSessionResolver.php`
- Create: `backend/app/Modules/Identity/Presentation/AuthController.php`
- Create: `backend/app/Http/Middleware/ForgetResolvedGuards.php`
- Create: `backend/config/identity.php`, `backend/lang/en/identity.php`, `backend/lang/ar/identity.php`
- Modify: `backend/config/auth.php` (replace), `backend/bootstrap/app.php`, `backend/app/Providers/AppServiceProvider.php`, `backend/routes/api.php`, `backend/tests/TestCase.php`
- Test: `backend/tests/Feature/AuthenticationTest.php`, `backend/tests/Unit/IdentityPoliciesTest.php`

**Interfaces:**
- Consumes: `ApiEnvelope::single/error`, `StandardColumns` macros, `HasStandardColumns`, `AddRequestId`.
- Produces:
  - Tables. `roles`: `standardColumns`, `name` varchar(128), `name_ar` varchar(128), `slug` varchar(64); partial unique indexes (`where deleted_at is null`) on `slug`, `name`, `name_ar`. `users`: `standardColumns`, `name`, `email` (stored lowercase, partial unique), `password`, `role_id` FK `roles` restrictOnDelete, `is_active` bool default true, `failed_login_attempts` smallint default 0 with `CHECK (failed_login_attempts >= 0)`, `locked_until` timestamptz null. `user_sessions`: `standardColumns`, `user_id` FK `users` cascadeOnDelete, `session_id` varchar(64) (sha256 of the token, partial unique), `ip_address` varchar(45) null, `user_agent` text null, `last_activity_at` timestamptz indexed.
  - The migration seeds four roles (UUIDv7 via `Str::uuid7()`): `developer` Developer مطور, `ceo` CEO الرئيس التنفيذي, `hr` HR الموارد البشرية, `manager` Manager مدير.
  - `Identity\Infrastructure\Eloquent\User` extends `Illuminate\Foundation\Auth\User`, `use HasStandardColumns`, `$guarded = []`, `$hidden = ['password']`, casts `password => hashed`, `is_active => boolean`, `locked_until => datetime`; `role(): BelongsTo<Role>`, `sessions(): HasMany<UserSession>`. `Role` (`HasStandardColumns`), `UserSession` (`HasStandardColumns`, casts `last_activity_at => datetime`, `user(): BelongsTo`).
  - `SessionToken::issue(): self`, `SessionToken::fromPresented(string $presented): self` (throws `InvalidArgumentException` unless `/^[0-9a-f]{64}$/D`), `->value`, `->fingerprint(): string` (sha256).
  - `LockoutPolicy::MAX_ATTEMPTS = 5`, `shouldLock(int $failures): bool`, `isLocked(?DateTimeInterface $until, DateTimeInterface $now): bool`, `lockedUntil(DateTimeInterface $now, int $minutes): DateTimeImmutable`.
  - `IdleTimeout::HOURS = 8`, `hasExpired(DateTimeInterface $lastActivity, DateTimeInterface $now): bool`.
  - `enum RefusalReason: string { InvalidCredentials = 'invalid_credentials'; AccountSuspended = 'account_suspended'; AccountLocked = 'account_locked'; }` with `status(): int` → 401 / 403 / 423.
  - `AuthenticationRefused::because(RefusalReason $r): self`, `public readonly RefusalReason $reason`.
  - `SessionAttribute::NAME = 'identity.session_id'` (request attribute holding the current `user_sessions.id`).
  - `AuthenticateUser::handle(string $email, #[SensitiveParameter] string $password, ?string $ip, ?string $userAgent): array{token: string, user_id: string}`.
  - `SignOut::handle(string $userId, ?string $sessionId): void`.
  - `Profile::for(string $userId): array{id: string, name: string, email: string, role: array{id: string, slug: string, name: string, name_ar: string}}`.
  - `BearerSessionResolver::forRequest(Request $request): ?User`.
  - Routes: `POST /auth/login` (`throttle:login`) → 201 `{token, token_type: "Bearer", idle_timeout_seconds: 28800, user: Profile}`; `POST /auth/logout` (`auth`) → 204; `GET /auth/me` (`auth`) → Profile.
  - Errors: refusal → `ApiEnvelope::error(status, reason value, __('identity.refusal.<reason>'))`; `ThrottleRequestsException` → 429 `rate_limited`, `__('identity.refusal.rate_limited')`.
  - `config/identity.php`: `lockout_minutes` (env `IDENTITY_LOCKOUT_MINUTES`, 30), `rate_limit.login.attempts` (10), `rate_limit.login.decay_minutes` (1).
  - `Tests\TestCase::makeUser(string $roleSlug = 'developer', array $attributes = []): User`, which creates an active user with password `secret123`, the role found by slug, and a unique default email (`"{$roleSlug}-".Str::lower(Str::random(8)).'@smart.test'`) unless `$attributes` gives one.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/IdentityPoliciesTest.php`:
```php
public function test_session_token(): void
{
    $t = SessionToken::issue();
    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $t->value);
    $this->assertSame(hash('sha256', $t->value), SessionToken::fromPresented($t->value)->fingerprint());
    $this->expectException(InvalidArgumentException::class);
    SessionToken::fromPresented(strtoupper($t->value));
}

public function test_lockout_and_idle_boundaries(): void
{
    $now = new DateTimeImmutable('2026-10-17 10:00:00 UTC');
    $this->assertFalse(LockoutPolicy::shouldLock(4));
    $this->assertTrue(LockoutPolicy::shouldLock(5));
    $this->assertEquals(new DateTimeImmutable('2026-10-17 10:30:00 UTC'), LockoutPolicy::lockedUntil($now, 30));
    $this->assertFalse(LockoutPolicy::isLocked($now, $now)); // a lock ending now is over
    $this->assertFalse(IdleTimeout::hasExpired($now->modify('-8 hours'), $now));
    $this->assertTrue(IdleTimeout::hasExpired($now->modify('-8 hours -1 second'), $now));
}
```

`tests/Feature/AuthenticationTest.php` (`use RefreshDatabase`):
```php
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
    foreach (range(1, 4) as $_) { $this->login(password: 'nope')->assertStatus(401); }
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
    foreach (range(1, 10) as $_) { $this->login(password: 'nope'); }
    $this->login()->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
}

public function test_unauthenticated_api_call_without_accept_header_is_401_json(): void
{
    $this->get('/api/v1/auth/me')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
}
```

- [ ] **Step 2: Run the tests and see them fail**

Run: `docker compose exec app php artisan test --filter='AuthenticationTest|IdentityPoliciesTest'`
Expected: FAIL (classes and `/auth/login` route missing).

- [ ] **Step 3: Write the migration, models and domain classes listed in Interfaces**

The migration uses partial unique indexes as `2026_10_17_000001_create_sites_tables.php` does. Copy the Domain classes from the CRM's `Domain/Authentication` (drop the challenge, impersonation and notification members).

- [ ] **Step 4: Implement `AuthenticateUser::handle` in `Application/Authentication/AuthenticateUser.php`**

Mirror the CRM's `AuthenticateUser`:
- Lowercase and trim the email.
- Lock the user row (`lockForUpdate`) inside `DB::transaction`.
- Order of checks: locked → `AccountLocked`; wrong password → increment `failed_login_attempts`, set `locked_until` when `LockoutPolicy::shouldLock`, return `InvalidCredentials` (or `AccountLocked` on the locking attempt); inactive → `AccountSuspended`; success → reset `failed_login_attempts`/`locked_until`, insert a `user_sessions` row with `SessionToken::issue()->fingerprint()`.
- The closure **returns** the refusal and `handle` throws after the transaction. If the closure threw, the failure count would roll back with it.

- [ ] **Step 5: Implement `BearerSessionResolver::forRequest` and wire the guard**

The resolver follows the CRM's `BearerSessionResolver` minus impersonation. On an expired session it soft-deletes the row and returns null. It refuses an inactive or locked user. Otherwise it touches `last_activity_at` and sets `SessionAttribute::NAME`.
- `AppServiceProvider::boot`: `Auth::viaRequest('hrms-bearer-session', fn (Request $r) => app(BearerSessionResolver::class)->forRequest($r))`, and `RateLimiter::for('login', …)` keyed `ip|lowercased email` with the `config('identity.rate_limit.login')` numbers.
- `config/auth.php`: replace the file with `defaults.guard = 'api'`; guards `api` (driver `hrms-bearer-session`, provider `users`) and `web` (session, kept for framework plumbing); provider `users` → `App\Modules\Identity\Infrastructure\Eloquent\User`. Drop `passwords` and `password_timeout`. This fixes the missing `App\Models\User` reference.
- `bootstrap/app.php`: append `ForgetResolvedGuards` (copy the CRM's: it forgets resolved guards when a bearer token is present, so a revoked token cannot ride a memoised user). Add `redirectGuestsTo(fn (Request $r) => $r->is('api/*') ? null : '/')`. Register the `AuthenticationRefused` and `ThrottleRequestsException` renders above the catch-all.

- [ ] **Step 6: Implement `SignOut`, `Profile`, `AuthController` and routes**

`AuthController::login` validates `email` (`required|string|email|max:255`) and `password` (`required|string`). `logout` passes `$request->attributes->get(SessionAttribute::NAME)`. Add `lang/{en,ar}/identity.php` with `refusal.{invalid_credentials,account_suspended,account_locked,rate_limited}`. Copy for the first three comes from the CRM's `lang/{ar,en}/identity.php`; en `invalid_credentials` must read `These credentials do not match our records.` (Task 9 asserts it). `rate_limited`: ar `محاولات كثيرة، يرجى المحاولة بعد قليل.`, en `Too many attempts. Try again shortly.` Add `makeUser()` to `tests/TestCase.php`.

- [ ] **Step 7: Run the tests and see them pass**

Run: `docker compose exec app php artisan test --filter='AuthenticationTest|IdentityPoliciesTest'`
Expected: PASS. Then run every gate in Global Constraints.

- [ ] **Step 8: Commit**

```bash
git add backend
git commit -m "Add accounts, bearer sessions, login and lockout"
```

---

### Task 2: Permissions and route authorization

**Files:**
- Create: `backend/database/migrations/2026_10_18_000002_create_permissions_tables.php`
- Create: `backend/app/Modules/Identity/Domain/Rbac/{Scope,PermissionDecision,AuthorizationRefused}.php`
- Create: `backend/app/Modules/Identity/Application/Rbac/AuthorizeAction.php`
- Create: `backend/app/Modules/Identity/Infrastructure/Eloquent/Permission.php`
- Create: `backend/app/Modules/Identity/Presentation/AuthorizePermission.php`
- Modify: `backend/app/Modules/Identity/Infrastructure/Eloquent/Role.php`, `backend/app/Modules/Identity/Application/Authentication/Profile.php`, `backend/bootstrap/app.php`, `backend/routes/api.php`, `backend/lang/{en,ar}/identity.php`, `backend/tests/TestCase.php`, `backend/tests/Feature/EmployeesApiTest.php`, `backend/tests/Feature/SitesApiTest.php`
- Test: `backend/tests/Feature/RbacTest.php`, `backend/tests/Unit/IdentityPoliciesTest.php`

**Interfaces:**
- Consumes: Task 1 `User`, `Role`, `makeUser()`, the `api` guard.
- Produces:
  - Tables. `permissions`: `standardColumns`, `resource` varchar(64), `action` varchar(64), `scope` varchar(16) with `CHECK (scope IN ('own','all'))`, `description` varchar(255) null, partial unique (`resource`, `action`, `scope`). `role_permissions`: `role_id` FK cascadeOnDelete, `permission_id` FK cascadeOnDelete, primary key (`role_id`, `permission_id`). It is a plain pivot (no soft delete): history lives in the audit log.
  - Seeded permissions (exactly these 15): `employee.view.own`, `employee.view.all`, `employee.create.all`, `employee.edit.all`, `employee.delete.all`, `employee.assign_manager.all`, `site.view.own`, `site.view.all`, `site.manage.all`, `site.assign_manager.all`, `user.view.all`, `user.manage.all`, `role.view.all`, `role.manage.all`, `audit_log.view.all`.
  - Seeded grants:
    - developer: every `.all` permission (13).
    - ceo: `employee.view.all`, `site.view.all`, `audit_log.view.all`.
    - hr: `employee.view.all`, `employee.create.all`, `employee.edit.all`, `employee.delete.all`, `employee.assign_manager.all`, `site.view.all`, `site.manage.all`, `site.assign_manager.all`, `user.view.all`.
    - manager: `employee.view.own`, `site.view.own`.
  - `enum Scope: string { Own = 'own'; All = 'all'; public function includes(self $other): bool }` (All includes Own).
  - `PermissionDecision` (`__construct(public array $scopes)` with `list<Scope>`; `granted(): bool`; `allows(Scope $required): bool`).
  - `AuthorizationRefused::of(string $ability): self`, rendered 403 `permission_denied` with `__('identity.refusal.permission_denied')`.
  - `AuthorizeAction::decide(string $userId, string $ability): PermissionDecision` (ability `resource.action`; scopes from the user's role via `role_permissions`); `AuthorizeAction::permissionsOf(string $userId): list<string>` (sorted triples).
  - `Role::permissions(): BelongsToMany<Permission>` over `role_permissions`.
  - `AuthorizePermission` middleware, alias `permission`, `ATTRIBUTE = 'identity.authorization'` (request attribute holding the `PermissionDecision`); route syntax `permission:employee.view`. It throws `AuthorizationRefused` unless `granted()`.
  - `Profile::for()` adds `permissions: list<string>`.
  - `Tests\TestCase::signIn(string $roleSlug = 'developer', array $attributes = []): User`: `makeUser` + `actingAs($user, 'api')`.
  - Route permissions (all inside one `Route::middleware('auth')` group; `health` and `auth/login` stay outside):

| Route | Permission |
|---|---|
| `GET employees`, `GET employees/{employee}`, `GET employees/{employee}/fare` | `employee.view` |
| `POST employees` | `employee.create` |
| `PATCH employees/{employee}`, `POST …/type-changes`, `POST …/pay-changes`, `PUT …/documents/{kind}`, `POST …/fare-overrides` | `employee.edit` |
| `DELETE employees/{employee}` | `employee.delete` |
| `GET sites`, `GET sites/{site}`, `GET residences`, `GET residences/{residence}`, `GET fares` | `site.view` |
| every other `sites`, `residences`, `fares` write | `site.manage` |
| `GET auth/me`, `POST auth/logout` | none (auth only) |

- [ ] **Step 1: Write the failing tests**

Unit (append to `IdentityPoliciesTest`):
```php
public function test_scope_and_decision(): void
{
    $this->assertTrue(Scope::All->includes(Scope::Own));
    $this->assertFalse(Scope::Own->includes(Scope::All));
    $this->assertFalse((new PermissionDecision([]))->granted());
    $this->assertTrue((new PermissionDecision([Scope::Own]))->allows(Scope::Own));
    $this->assertFalse((new PermissionDecision([Scope::Own]))->allows(Scope::All));
}
```

`tests/Feature/RbacTest.php` (`use RefreshDatabase`):
```php
public function test_seeded_matrix(): void
{
    $grants = fn (string $slug) => Role::where('slug', $slug)->firstOrFail()->permissions
        ->map(fn ($p) => "{$p->resource}.{$p->action}.{$p->scope}")->sort()->values()->all();
    $this->assertSame(15, Permission::count());
    $this->assertSame(['employee.view.own', 'site.view.own'], $grants('manager'));
    $this->assertSame(['audit_log.view.all', 'employee.view.all', 'site.view.all'], $grants('ceo'));
    $this->assertCount(9, $grants('hr'));
    $this->assertNotContains('user.manage.all', $grants('hr'));
    $this->assertCount(13, $grants('developer'));
}

public function test_every_api_route_requires_auth_and_a_permission(): void
{
    $open = ['api/v1/health', 'api/v1/auth/login'];
    $authOnly = ['api/v1/auth/me', 'api/v1/auth/logout'];
    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1') || in_array($route->uri(), $open, true)) { continue; }
        $mw = $route->gatherMiddleware();
        $this->assertContains('auth', $mw, $route->uri());
        if (! in_array($route->uri(), $authOnly, true)) {
            $this->assertNotEmpty(array_filter($mw, fn ($m) => str_starts_with($m, 'permission:')), $route->uri());
        }
    }
}

public function test_unauthenticated_is_401(): void
{
    $this->getJson('/api/v1/employees')->assertStatus(401);
}

public function test_role_matrix_on_real_routes(): void
{
    $this->signIn('manager');
    $this->postJson('/api/v1/employees', [])->assertStatus(403)->assertJsonPath('error.code', 'permission_denied');
    $this->signIn('ceo');
    $this->getJson('/api/v1/sites')->assertOk();
    $this->postJson('/api/v1/sites', ['name_ar' => 'موقع', 'kind' => 'work'])->assertStatus(403);
    $this->signIn('hr');
    $this->postJson('/api/v1/sites', ['name_ar' => 'موقع', 'kind' => 'work'])->assertCreated();
}

public function test_permission_is_checked_by_name_not_role(): void
{
    $role = Role::create(['slug' => 'auditor', 'name' => 'Auditor', 'name_ar' => 'مراجع']);
    $role->permissions()->attach(Permission::where(['resource' => 'site', 'action' => 'view', 'scope' => 'all'])->value('id'));
    $this->signIn('auditor');
    $this->getJson('/api/v1/sites')->assertOk();
    $this->getJson('/api/v1/employees')->assertStatus(403);
}

public function test_me_lists_permissions(): void
{
    $token = $this->postJson('/api/v1/auth/login', ['email' => $this->makeUser('hr')->email, 'password' => 'secret123'])->json('data.token');
    $perms = $this->withToken($token)->getJson('/api/v1/auth/me')->json('data.permissions');
    $this->assertContains('employee.create.all', $perms);
    $this->assertNotContains('user.manage.all', $perms);
}
```

In `EmployeesApiTest` and `SitesApiTest`, add `protected function setUp(): void { parent::setUp(); $this->signIn('hr'); }`. Their existing assertions stay unchanged.

- [ ] **Step 2: Run the tests and see them fail**

Run: `docker compose exec app php artisan test --filter='RbacTest|IdentityPoliciesTest'`
Expected: FAIL (`permissions` table, `signIn`, `permission` alias missing).

- [ ] **Step 3: Write the migration with the seed data above, `Permission`, `Role::permissions()`, and the Rbac domain classes**

- [ ] **Step 4: Implement `AuthorizeAction` and `AuthorizePermission`; register the alias and the 403 render in `bootstrap/app.php`; wrap `routes/api.php` per the table**

There is no role-slug bypass for the Developer: the Developer passes because its role holds the permissions.

- [ ] **Step 5: Run the tests and see them pass**

Run: `docker compose exec app php artisan test`
Expected: PASS (including the 1a Employees and Sites suites signed in as `hr`). Then run every gate.

- [ ] **Step 6: Commit**

```bash
git add backend
git commit -m "Add data-driven permissions and guard every API route"
```

---

### Task 3: Append-only audit log on every write

**Files:**
- Create: `backend/database/migrations/2026_10_18_000003_create_audit_log.php`
- Create: `backend/app/Modules/Audit/Infrastructure/{AuditLog,Audited}.php`
- Create: `backend/app/Modules/Audit/Infrastructure/Eloquent/AuditLogEntry.php`
- Create: `backend/app/Modules/Audit/Application/AuditLogQuery.php`
- Create: `backend/app/Modules/Audit/Presentation/AuditLogController.php`
- Create: `backend/app/Modules/Identity/Domain/Authentication/IdentityAuditEvents.php`
- Move: `backend/app/Modules/Sites/Application/Paginates.php` → `backend/app/Support/Http/Paginates.php` (namespace `App\Support\Http`; update the `use` in `SiteService` and `FareService`)
- Modify: `backend/app/Support/Database/HasStandardColumns.php`, `backend/app/Support/Database/StandardColumns.php` (docblock line about users), the nine 1a models in `backend/app/Modules/{Employees,Sites}/Infrastructure/Eloquent/` (`use Audited;`), `backend/app/Modules/Identity/Application/Authentication/{AuthenticateUser,SignOut}.php`, `backend/routes/api.php`
- Test: `backend/tests/Feature/AuditLogTest.php`

**Interfaces:**
- Consumes: Task 1 login flow, Task 2 `permission` middleware and `signIn()`.
- Produces:
  - Table `audit_log` (not a business table; no `standardColumns`): `id` uuid PK, `user_id` uuid null (the actor), `event` varchar(64), `entity_type` varchar(64) (the table name), `entity_id` uuid null, `old_values` jsonb null, `new_values` jsonb null, `ip_address` inet null, `user_agent` varchar(512) null, `request_id` varchar(64) null, `occurred_at` timestamptz not null. Indexes `(entity_type, entity_id, occurred_at desc)`, `(user_id, occurred_at desc)`, `(occurred_at desc)`. Append-only: the CRM's `audit_log_is_append_only()` plpgsql function with a `BEFORE UPDATE OR DELETE … FOR EACH ROW` trigger and a `BEFORE TRUNCATE … FOR EACH STATEMENT` trigger. No partitions.
  - `AuditLog::record(string $event, string $entityType, ?string $entityId, ?array $oldValues = null, ?array $newValues = null, ?string $actorId = null): void`:
    - `$actorId` defaults to the authenticated user (see Global Constraints).
    - `request_id` comes from the request attribute `request_id`; `ip_address` and `user_agent` from the request.
    - JSON is encoded with `JSON_UNESCAPED_UNICODE`; it throws `InvalidArgumentException` on any float value.
    - The id is `Str::uuid7()`; `occurred_at` is `now()`.
  - Trait `Audited`: on `created` records `CREATED` with `new_values` = raw attributes; on `updated` records `UPDATED` with only the changed keys, old from `getRawOriginal`, new from `getChanges`; on `deleted` records `DELETED` with `old_values` = raw attributes. `created_at`, `updated_at`, `created_by` and `updated_by` are excluded. `entity_type` = `$model->getTable()`.
  - `HasStandardColumns` gains `bootHasStandardColumns()`: `creating` fills `created_by` and `updated_by`, `updating` fills `updated_by`, both with the actor read as in Global Constraints.
  - `IdentityAuditEvents` constants (CRM names): `LOGIN_SUCCEEDED`, `LOGIN_FAILED`, `ACCOUNT_LOCKED`, `LOGIN_BLOCKED`, `LOGOUT`, `USER_CREATED`, `USER_UPDATED`, `USER_ACTIVATED`, `USER_DEACTIVATED`, `PASSWORD_SET`, `ADMIN_SESSION_TERMINATED`, `ROLE_CREATED`, `ROLE_UPDATED`, `ROLE_PERMISSIONS_UPDATED`. Task 5 adds `SITE_MANAGERS_UPDATED` to the same class.
  - Login events (entity `users`):
    - `LOGIN_SUCCEEDED`: actor = the account, `new_values {session_id}`.
    - `LOGIN_FAILED`: `{failed_login_attempts}` before/after. For an unknown email: `entity_id` null, `new_values {email}`.
    - `ACCOUNT_LOCKED`: `{locked_until}` before/after.
    - `LOGIN_BLOCKED`: inactive account.
    - `LOGOUT`: `old_values {session_id}`.
  - `AuditLogEntry` (`$table = 'audit_log'`, `$timestamps = false`, casts `old_values`/`new_values` → array, `occurred_at` → datetime, `user(): BelongsTo<User>`).
  - `AuditLogQuery::list(array $filters): array{data, pagination}` uses `App\Support\Http\Paginates`. Filters: `user_id`, `entity_type`, `entity_id`, `from` and `to` (Y-m-d **Cairo** dates, inclusive, converted to a UTC range), `per_page` (1–100, default 20). Sorted `occurred_at desc, id desc`. Each item carries `user: {id, name}|null`.
  - Route `GET /audit-log` (`permission:audit_log.view`).

- [ ] **Step 1: Write the failing tests** (`tests/Feature/AuditLogTest.php`, `use RefreshDatabase`)

```php
public function test_employee_writes_are_audited_with_actor_request_and_values(): void
{
    $hr = $this->signIn('hr');
    $res = $this->postJson('/api/v1/employees', ['name_ar' => 'أحمد علي', 'gender' => 'male', 'type' => 'office', 'effective_from' => '2026-01-01', 'salary' => '9000']);
    $id = $res->json('data.id');
    $row = DB::table('audit_log')->where(['entity_type' => 'employees', 'entity_id' => $id])->first();
    $this->assertSame('CREATED', $row->event);
    $this->assertSame($hr->id, $row->user_id);
    $this->assertSame($res->headers->get('X-Request-Id'), $row->request_id);
    $this->assertSame('أحمد علي', json_decode($row->new_values, true)['name_ar']);
    $this->assertStringContainsString('أحمد', $row->new_values); // stored unescaped
    $this->assertSame('9000', json_decode(DB::table('audit_log')->where('entity_type', 'employee_pay_changes')->value('new_values'), true)['salary']);
    $this->assertSame($hr->id, DB::table('employees')->where('id', $id)->value('created_by'));

    $dev = $this->signIn('developer');
    $this->patchJson("/api/v1/employees/$id", ['name_ar' => 'أحمد محمد'])->assertOk();
    $upd = DB::table('audit_log')->where(['entity_id' => $id, 'event' => 'UPDATED'])->first();
    $this->assertSame(['name_ar' => 'أحمد علي'], json_decode($upd->old_values, true));
    $this->assertSame(['name_ar' => 'أحمد محمد'], json_decode($upd->new_values, true));
    $this->assertSame($dev->id, DB::table('employees')->where('id', $id)->value('updated_by'));

    $this->deleteJson("/api/v1/employees/$id")->assertNoContent();
    $this->assertDatabaseHas('audit_log', ['entity_id' => $id, 'event' => 'DELETED']);
}

public function test_site_writes_are_audited(): void
{
    $this->signIn('hr');
    $id = $this->postJson('/api/v1/sites', ['name_ar' => 'سموحة', 'kind' => 'work'])->json('data.id');
    $this->assertDatabaseHas('audit_log', ['entity_type' => 'sites', 'entity_id' => $id, 'event' => 'CREATED']);
}

public function test_audit_log_is_append_only(): void
{
    AuditLog::record('TEST', 'employees', null);
    foreach (['update audit_log set event = \'X\'', 'delete from audit_log', 'truncate audit_log'] as $sql) {
        try {
            DB::transaction(fn () => DB::statement($sql));   // savepoint: one refusal must not abort the next statement
            $this->fail("$sql succeeded");
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }
    $this->assertSame(1, DB::table('audit_log')->count());
}

public function test_login_events(): void
{
    $u = $this->makeUser('hr', ['email' => 'hr@smart.test']);
    foreach (range(1, 5) as $_) { $this->postJson('/api/v1/auth/login', ['email' => 'hr@smart.test', 'password' => 'nope']); }
    $this->assertSame(5, DB::table('audit_log')->where(['event' => 'LOGIN_FAILED', 'entity_id' => $u->id])->count());
    $this->assertDatabaseHas('audit_log', ['event' => 'ACCOUNT_LOCKED', 'entity_id' => $u->id]);
    $this->postJson('/api/v1/auth/login', ['email' => 'ghost@smart.test', 'password' => 'x']);
    $this->assertSame(['email' => 'ghost@smart.test'], json_decode(DB::table('audit_log')->whereNull('entity_id')->where('event', 'LOGIN_FAILED')->value('new_values'), true));

    $v = $this->makeUser('ceo', ['email' => 'ceo@smart.test']);
    $token = $this->postJson('/api/v1/auth/login', ['email' => 'ceo@smart.test', 'password' => 'secret123'])->json('data.token');
    $this->assertDatabaseHas('audit_log', ['event' => 'LOGIN_SUCCEEDED', 'user_id' => $v->id, 'entity_id' => $v->id]);
    $this->withToken($token)->postJson('/api/v1/auth/logout');
    $this->assertDatabaseHas('audit_log', ['event' => 'LOGOUT', 'entity_id' => $v->id]);
}

public function test_audit_endpoint_filters_by_actor_type_and_cairo_dates(): void
{
    $this->travelTo('2026-10-16 22:30:00');               // 2026-10-17 01:30 in Cairo
    $hr = $this->signIn('hr');
    $this->postJson('/api/v1/sites', ['name_ar' => 'سموحة', 'kind' => 'work']);
    $this->signIn('ceo');
    $this->getJson("/api/v1/audit-log?user_id={$hr->id}&entity_type=sites&from=2026-10-17&to=2026-10-17")
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.user.id', $hr->id);
    $this->getJson('/api/v1/audit-log?from=2026-10-16&to=2026-10-16')->assertJsonCount(0, 'data');
    $this->signIn('manager');
    $this->getJson('/api/v1/audit-log')->assertStatus(403);
}
```

- [ ] **Step 2: Run the tests and see them fail**

Run: `docker compose exec app php artisan test --filter=AuditLogTest`
Expected: FAIL (no `audit_log` table).

- [ ] **Step 3: Write the migration (table, indexes, trigger function, both triggers)**

- [ ] **Step 4: Implement `AuditLog`, `Audited`, the `HasStandardColumns` hooks; add `use Audited;` to `Employee`, `EmployeeTypeChange`, `EmployeePayChange`, `EmployeeDocument`, `Site`, `SiteAllowanceRule`, `Residence`, `Fare`, `EmployeeFareOverride`**

The audit row is written inside the same transaction as the business write, so a failed audit insert rolls the write back. `User`, `Role` and `UserSession` do not use `Audited`: they get explicit Identity events, which keeps password hashes and per-request `last_activity_at` touches out of the log.

- [ ] **Step 5: Add the login events to `AuthenticateUser` and `SignOut`**

They are written inside the existing transaction, so a failure's audit row commits with its counter.

- [ ] **Step 6: Move `Paginates`; implement `AuditLogEntry`, `AuditLogQuery`, `AuditLogController`, the route**

- [ ] **Step 7: Run the tests and see them pass**

Run: `docker compose exec app php artisan test`
Expected: PASS. Then run every gate (deptrac must still report 0 violations after the `Paginates` move).

- [ ] **Step 8: Commit**

```bash
git add backend
git commit -m "Add append-only audit log and record every write and login"
```

---

### Task 4: Developer administration API (accounts and roles)

**Files:**
- Create: `backend/app/Modules/Identity/Application/Administration/{UserAdministration,RoleAdministration}.php`
- Create: `backend/app/Modules/Identity/Presentation/{UserController,RoleController,CreateUserCommand}.php`
- Modify: `backend/routes/api.php`, `backend/bootstrap/app.php` (`->withCommands([CreateUserCommand::class])`), `backend/lang/{en,ar}/identity.php`
- Test: `backend/tests/Feature/AdministrationTest.php`

**Interfaces:**
- Consumes: Task 1 `User`/`Role`/`UserSession`; Task 2 `Permission`, `permission` middleware; Task 3 `AuditLog::record`, `IdentityAuditEvents`, `App\Support\Http\Paginates`.
- Produces:
  - `UserAdministration`:
    - `list(array $filters): array{data, pagination}`: filters `search` (name/email ilike), `active` (`true|false`), `per_page`. Items `{id, name, email, role: {id, slug, name, name_ar}, is_active, locked_until, failed_login_attempts, created_at}`.
    - `create(array $data): array`: email trimmed and lowercased.
    - `show(string $id): array`.
    - `update(string $id, array $data): array`: `name`, `email`, `role_id`.
    - `setActive(string $id, bool $active): array`: deactivate revokes every session; reactivate clears `failed_login_attempts` and `locked_until`.
    - `setPassword(string $id, string $password): void`: revokes every session and clears the lock.
    - `sessions(string $id): list<array{id, ip_address, user_agent, last_activity_at, signed_in_at}>`: live, non-expired sessions only.
    - `terminateSession(string $userId, string $sessionId): void`: 404 if the session is not that user's.
  - `RoleAdministration`:
    - `list(): list<array{id, slug, name, name_ar, permissions: list<string>, users_count: int}>`.
    - `create(array $data): array`: `slug` matches `/^[a-z][a-z0-9_]{1,63}$/`, `name`, `name_ar`.
    - `update(string $id, array $data): array`: `name`, `name_ar`.
    - `syncPermissions(string $id, list<string> $permissionIds): array`.
    - `permissions(): list<array{id, resource, action, scope, key}>`.
    - `static assertRoleManagerRemains(string $field): void`: throws `ValidationException([$field => __('identity.last_role_manager')])` unless at least one active user's role holds `role.manage.all`. Callers run it inside their transaction, after the change.
  - Validation: password `['required', Password::min(8)->letters()->numbers()]`; `email` `required|email|max:255` + unique among live users (compared lowercase); `role_id` exists in live roles.
  - Audit events (all entity `users` or `roles`):
    - `USER_CREATED` new `{name, email, role_id, is_active}`.
    - `USER_UPDATED` changed keys old/new.
    - `USER_ACTIVATED` / `USER_DEACTIVATED`.
    - `PASSWORD_SET` with no values (never a password or hash).
    - `ADMIN_SESSION_TERMINATED` old `{session_id}`.
    - `ROLE_CREATED`.
    - `ROLE_UPDATED`.
    - `ROLE_PERMISSIONS_UPDATED` old/new `{permissions: sorted triples}`.
  - Routes:

| Route | Permission |
|---|---|
| `GET users`, `GET users/{user}` | `user.view` |
| `POST users`, `PATCH users/{user}`, `PATCH users/{user}/deactivate`, `PATCH users/{user}/reactivate`, `PUT users/{user}/password` (204), `GET users/{user}/sessions`, `DELETE users/{user}/sessions/{session}` (204) | `user.manage` |
| `GET roles`, `GET permissions` | `role.view` |
| `POST roles`, `PATCH roles/{role}`, `PATCH roles/{role}/permissions` (body `{permission_ids: uuid[]}`, `present|array`) | `role.manage` |

  - Console command `identity:create-user {email} {--name=} {--role=developer}` prompts for the password with `secret()`, validates it with the same rule, and creates the account through `UserAdministration::create`. This is how the first Developer is created on a server.

- [ ] **Step 1: Write the failing tests** (`tests/Feature/AdministrationTest.php`, `use RefreshDatabase`)

```php
private function token(User $u): string
{
    return $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'secret123'])->json('data.token');
}

/** Status of GET /auth/me with $token, then back to acting as $actor. */
private function probe(string $token, User $actor): int
{
    $status = $this->withToken($token)->getJson('/api/v1/auth/me')->status();
    $this->flushHeaders();
    $this->actingAs($actor, 'api');

    return $status;
}

public function test_developer_creates_an_account_and_only_the_developer_can(): void
{
    $this->signIn('developer');
    $res = $this->postJson('/api/v1/users', ['name' => 'منى', 'email' => 'mona@smart.test', 'password' => 'abcd1234', 'role_id' => Role::where('slug', 'manager')->value('id')])
        ->assertCreated()->assertJsonMissingPath('data.password');
    $new = DB::table('audit_log')->where(['event' => 'USER_CREATED', 'entity_id' => $res->json('data.id')])->value('new_values');
    $this->assertStringNotContainsString('password', $new);
    $this->signIn('hr');
    $this->postJson('/api/v1/users', [])->assertStatus(403);
    $this->getJson('/api/v1/users')->assertOk();                    // HR may list accounts to pick managers
}

public function test_create_normalises_email_and_rejects_case_duplicate(): void
{
    $this->signIn('developer');
    $role = Role::where('slug', 'manager')->value('id');
    $this->postJson('/api/v1/users', ['name' => 'أ', 'email' => ' Ali@Smart.Test ', 'password' => 'abcd1234', 'role_id' => $role])
        ->assertCreated()->assertJsonPath('data.email', 'ali@smart.test');
    $this->postJson('/api/v1/users', ['name' => 'ب', 'email' => 'ALI@smart.test', 'password' => 'abcd1234', 'role_id' => $role])
        ->assertStatus(422)->assertJsonPath('error.details.0.field', 'email');
    $this->postJson('/api/v1/users', ['name' => 'ج', 'email' => 'c@smart.test', 'password' => 'abcdefgh', 'role_id' => $role])
        ->assertStatus(422)->assertJsonPath('error.details.0.field', 'password');
}

public function test_deactivate_and_password_set_end_sessions(): void
{
    $m = $this->makeUser('manager');
    $t1 = $this->token($m);
    $dev = $this->signIn('developer');
    $this->patchJson("/api/v1/users/{$m->id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);
    $this->assertSame(401, $this->probe($t1, $dev));
    $this->patchJson("/api/v1/users/{$m->id}/reactivate")->assertOk();
    $t2 = $this->token($m);
    $this->putJson("/api/v1/users/{$m->id}/password", ['password' => 'newpass99'])->assertNoContent();
    $this->assertSame(401, $this->probe($t2, $dev));
    $this->assertDatabaseHas('audit_log', ['event' => 'PASSWORD_SET', 'entity_id' => $m->id, 'new_values' => null]);
}

public function test_password_set_unlocks_a_locked_account(): void
{
    $m = $this->makeUser('manager', ['failed_login_attempts' => 5, 'locked_until' => now()->addMinutes(30)]);
    $this->signIn('developer');
    $this->putJson("/api/v1/users/{$m->id}/password", ['password' => 'newpass99'])->assertNoContent();
    $this->postJson('/api/v1/auth/login', ['email' => $m->email, 'password' => 'newpass99'])->assertCreated();
}

public function test_sessions_are_listed_and_terminated(): void
{
    $m = $this->makeUser('manager');
    $t = $this->token($m);
    $this->signIn('developer');
    $sid = $this->getJson("/api/v1/users/{$m->id}/sessions")->assertJsonCount(1, 'data')->json('data.0.id');
    $this->deleteJson("/api/v1/users/{$m->id}/sessions/$sid")->assertNoContent();
    $this->withToken($t)->getJson('/api/v1/auth/me')->assertStatus(401);
    $this->assertDatabaseHas('audit_log', ['event' => 'ADMIN_SESSION_TERMINATED', 'entity_id' => $m->id]);
}

public function test_role_matrix_is_editable_and_audited(): void
{
    $this->signIn('developer');
    $this->getJson('/api/v1/permissions')->assertJsonCount(15, 'data');
    $role = $this->postJson('/api/v1/roles', ['slug' => 'auditor', 'name' => 'Auditor', 'name_ar' => 'مراجع'])->assertCreated()->json('data.id');
    $pid = Permission::where(['resource' => 'audit_log', 'action' => 'view', 'scope' => 'all'])->value('id');
    $this->patchJson("/api/v1/roles/$role/permissions", ['permission_ids' => [$pid]])->assertOk()->assertJsonPath('data.permissions', ['audit_log.view.all']);
    $row = DB::table('audit_log')->where(['event' => 'ROLE_PERMISSIONS_UPDATED', 'entity_id' => $role])->first();
    $this->assertSame(['permissions' => []], json_decode($row->old_values, true));
    $this->assertSame(['permissions' => ['audit_log.view.all']], json_decode($row->new_values, true));
}

public function test_cannot_remove_the_last_role_manager(): void
{
    $dev = $this->signIn('developer');
    $devRole = Role::where('slug', 'developer')->value('id');
    $keep = Permission::where('resource', '!=', 'role')->pluck('id')->all();
    $this->patchJson("/api/v1/roles/$devRole/permissions", ['permission_ids' => $keep])->assertStatus(422);
    $this->patchJson("/api/v1/users/{$dev->id}/deactivate")->assertStatus(422);
    $this->patchJson("/api/v1/users/{$dev->id}", ['role_id' => Role::where('slug', 'hr')->value('id')])->assertStatus(422);
    $this->makeUser('developer');                                   // a second holder makes it allowed
    $this->patchJson("/api/v1/users/{$dev->id}/deactivate")->assertOk();
}

public function test_create_user_command(): void
{
    $this->artisan('identity:create-user', ['email' => 'Dev@Smart.Test', '--name' => 'Yousef'])
        ->expectsQuestion('Password', 'devpass123')->assertSuccessful();
    $this->postJson('/api/v1/auth/login', ['email' => 'dev@smart.test', 'password' => 'devpass123'])
        ->assertCreated()->assertJsonPath('data.user.role.slug', 'developer');
}
```

- [ ] **Step 2: Run the tests and see them fail**

Run: `docker compose exec app php artisan test --filter=AdministrationTest`
Expected: FAIL (routes missing).

- [ ] **Step 3: Implement `UserAdministration` and `UserController` + routes**

Each mutating method runs in `DB::transaction` and calls `AuditLog::record`. `update` (when `role_id` changes) and `setActive(false)` call `RoleAdministration::assertRoleManagerRemains('role_id'|'is_active')`.

- [ ] **Step 4: Implement `RoleAdministration` and `RoleController` + routes**

`syncPermissions` uses `$role->permissions()->sync($ids)`, then calls `assertRoleManagerRemains('permission_ids')`. Unknown permission ids → 422 (`exists` rule on `permission_ids.*`). Add `identity.last_role_manager` to both lang files: en `At least one active account must keep the right to manage roles.`, ar `يجب أن يبقى حساب نشط واحد على الأقل يملك صلاحية إدارة الأدوار.`.

- [ ] **Step 5: Implement `CreateUserCommand`; register it with `withCommands`**

- [ ] **Step 6: Run the tests and see them pass**

Run: `docker compose exec app php artisan test`
Expected: PASS. Then run every gate.

- [ ] **Step 7: Commit**

```bash
git add backend
git commit -m "Add developer administration API for accounts and roles"
```

---

### Task 5: Manager assignments and own-scope filtering

**Files:**
- Create: `backend/database/migrations/2026_10_18_000004_create_manager_assignments.php`
- Create: `backend/app/Modules/Employees/Infrastructure/Eloquent/EmployeeManagerAssignment.php`
- Create: `backend/app/Modules/Employees/Presentation/EnsureEmployeeInScope.php`
- Create: `backend/app/Modules/Identity/Presentation/RequestScope.php`
- Modify: `backend/app/Modules/Employees/Infrastructure/Eloquent/Employee.php`, `backend/app/Modules/Employees/Application/EmployeeService.php`, `backend/app/Modules/Employees/Presentation/EmployeeController.php`, `backend/app/Modules/Sites/Infrastructure/Eloquent/Site.php`, `backend/app/Modules/Sites/Application/SiteService.php`, `backend/app/Modules/Sites/Presentation/SiteController.php`, `backend/app/Modules/Identity/Domain/Authentication/IdentityAuditEvents.php` (add `SITE_MANAGERS_UPDATED`), `backend/bootstrap/app.php` (alias), `backend/routes/api.php`
- Test: `backend/tests/Feature/ManagerScopeTest.php`

**Interfaces:**
- Consumes: Task 2 `AuthorizePermission::ATTRIBUTE`, `PermissionDecision`, `Scope`; Task 3 `Audited`, `AuditLog::record`; Task 4 `users` admin (managers are ordinary active users).
- Produces:
  - Table `employee_manager_assignments`: `standardColumns`, `employee_id` FK `employees`, `manager_id` FK `users`, `effective_from` date, `unique(employee_id, effective_from)`. This unique index is the DB guarantee that at most one assignment is in force per employee on any date. The model uses `Audited`.
  - Table `manager_sites` (plain pivot, undated): `manager_id` FK `users` cascadeOnDelete, `site_id` FK `sites` cascadeOnDelete, primary key (`manager_id`, `site_id`).
  - `Employee::managerAssignments(): HasMany<EmployeeManagerAssignment>`; `Site::managers(): BelongsToMany<User>` via `manager_sites` (`site_id`, `manager_id`).
  - `RequestScope::ownerId(Request $request): ?string`: null when the request's `PermissionDecision` allows `Scope::All`, else the authenticated user's id. It throws `LogicException` when the route has no `permission:` middleware.
  - `EmployeeService` changes:
    - `list(array $filters, ?string $ownerId = null)`: with an owner, only employees whose assignment in force today has `manager_id = $ownerId` (same correlated-subquery style as `current_type`).
    - `isManagedBy(string $employeeId, string $userId): bool`.
    - `assignManager(string $id, array{manager_id: string, effective_from: string} $data): array`: duplicate date → 422 through the existing `assertFreeDate`.
    - `show()` adds `current_manager: {id, name}|null` and `manager_assignments: list<{id, manager_id, manager_name, effective_from}>` sorted by date.
  - `EnsureEmployeeInScope` middleware, alias `employee.scope`: when `RequestScope::ownerId` is non-null and `! isManagedBy(route('employee'), ownerId)` → `ModelNotFoundException` (404, so ids are not confirmed to exist). Apply it to every `employees/{employee}…` route, including the two `FareController` employee routes, after `permission:`.
  - `SiteService` changes:
    - `list(array $filters, ?string $ownerId = null)`: with an owner, `kind = 'leave'` or the site is in the owner's `manager_sites`.
    - `show(string $id, ?string $ownerId = null)`: out of scope → 404; adds `managers: list<{id, name}>`.
    - `setManagers(string $id, list<string> $managerIds): array`: sync; audit `SITE_MANAGERS_UPDATED`, entity `sites`, old/new `{manager_ids: sorted}`.
  - Routes: `POST employees/{employee}/manager-assignments` (`permission:employee.assign_manager`, `employee.scope`) → 201 employee show; `PUT sites/{site}/managers` (`permission:site.assign_manager`) body `{manager_ids: uuid[]}` (`present|array`) → 200 site show.
  - Validation: `manager_id` / `manager_ids.*` → `Rule::exists('users', 'id')->where('is_active', true)->whereNull('deleted_at')`; `effective_from` `required|date_format:Y-m-d`.

- [ ] **Step 1: Write the failing tests** (`tests/Feature/ManagerScopeTest.php`, `use RefreshDatabase`)

```php
private function employee(string $name): string
{
    return $this->postJson('/api/v1/employees', ['name_ar' => $name, 'gender' => 'male', 'type' => 'engineer', 'effective_from' => '2026-01-01', 'salary' => '9000'])->json('data.id');
}

private function assign(string $emp, User $m, string $from): TestResponse
{
    return $this->postJson("/api/v1/employees/$emp/manager-assignments", ['manager_id' => $m->id, 'effective_from' => $from]);
}

public function test_manager_sees_only_current_employees(): void
{
    $this->travelTo('2026-10-17 09:00:00');
    [$a, $b] = [$this->makeUser('manager'), $this->makeUser('manager')];
    $this->signIn('hr');
    [$e1, $e2, $e3] = [$this->employee('أ'), $this->employee('ب'), $this->employee('ج')];
    $this->assign($e1, $a, '2026-10-01')->assertCreated()->assertJsonPath('data.current_manager.id', $a->id);
    $this->assign($e2, $a, '2026-10-01');
    $this->assign($e3, $b, '2026-10-01');
    $this->assign($e3, $b, '2026-10-01')->assertStatus(422);              // duplicate date
    $this->assertDatabaseHas('audit_log', ['entity_type' => 'employee_manager_assignments', 'event' => 'CREATED']);

    $this->actingAs($a, 'api');
    $this->getJson('/api/v1/employees')->assertJsonCount(2, 'data');
    $this->getJson("/api/v1/employees/$e1")->assertOk();
    $this->getJson("/api/v1/employees/$e3")->assertNotFound();
    $this->getJson("/api/v1/employees/$e3/fare?site_id=".Str::uuid7())->assertNotFound();
    $this->assign($e1, $b, '2026-11-01')->assertStatus(403);              // managers cannot reassign
}

public function test_reassignment_follows_the_cairo_date(): void
{
    $this->travelTo('2026-10-17 12:00:00');
    [$a, $b] = [$this->makeUser('manager'), $this->makeUser('manager')];
    $this->signIn('hr');
    $e = $this->employee('أ');
    $this->assign($e, $a, '2026-10-01');
    $this->assign($e, $b, '2026-10-18');
    $this->actingAs($a, 'api');
    $this->getJson("/api/v1/employees/$e")->assertOk();
    $this->travelTo('2026-10-17 21:30:00');                               // 2026-10-18 00:30 in Cairo
    $this->getJson("/api/v1/employees/$e")->assertNotFound();
    $this->actingAs($b, 'api');
    $this->getJson("/api/v1/employees/$e")->assertOk()->assertJsonPath('data.current_manager.id', $b->id);
}

public function test_only_active_users_can_be_assigned(): void
{
    $gone = $this->makeUser('manager', ['is_active' => false]);
    $this->signIn('hr');
    $this->assign($this->employee('أ'), $gone, '2026-10-01')->assertStatus(422)->assertJsonPath('error.details.0.field', 'manager_id');
}

public function test_site_managers_scope_the_site_list(): void
{
    $a = $this->makeUser('manager');
    $this->signIn('hr');
    $mine = $this->postJson('/api/v1/sites', ['name_ar' => 'سموحة', 'kind' => 'work'])->json('data.id');
    $other = $this->postJson('/api/v1/sites', ['name_ar' => 'العامرية', 'kind' => 'work'])->json('data.id');
    $this->postJson('/api/v1/sites', ['name_ar' => 'اجازة الجمعة', 'kind' => 'leave', 'leave_code' => 'friday']);
    $this->putJson("/api/v1/sites/$mine/managers", ['manager_ids' => [$a->id]])->assertOk()->assertJsonPath('data.managers.0.id', $a->id);
    $row = DB::table('audit_log')->where(['event' => 'SITE_MANAGERS_UPDATED', 'entity_id' => $mine])->first();
    $this->assertSame(['manager_ids' => []], json_decode($row->old_values, true));

    $this->actingAs($a, 'api');
    $names = collect($this->getJson('/api/v1/sites')->json('data'))->pluck('name_ar')->sort()->values()->all();
    $this->assertSame(['اجازة الجمعة', 'سموحة'], $names);
    $this->getJson("/api/v1/sites/$other")->assertNotFound();
    $this->putJson("/api/v1/sites/$mine/managers", ['manager_ids' => []])->assertStatus(403);
}
```

- [ ] **Step 2: Run the tests and see them fail**

Run: `docker compose exec app php artisan test --filter=ManagerScopeTest`
Expected: FAIL (route and tables missing).

- [ ] **Step 3: Write the migration and `EmployeeManagerAssignment`; add the relations**

- [ ] **Step 4: Implement `RequestScope`, `EnsureEmployeeInScope`, the `EmployeeService` changes, the controller action and routes**

`EmployeeController::index` and `SiteController::{index, show}` pass `RequestScope::ownerId($request)`.

- [ ] **Step 5: Implement the `SiteService` changes and `PUT sites/{site}/managers`**

- [ ] **Step 6: Run the tests and see them pass**

Run: `docker compose exec app php artisan test`
Expected: PASS. Then run every gate.

- [ ] **Step 7: Commit**

```bash
git add backend
git commit -m "Add dated manager assignments, manager sites and own-scope filtering"
```

---

### Task 6: Frontend login and session

**Files:**
- Create: `frontend/src/lib/auth.tsx`, `frontend/src/pages/login.tsx`, `frontend/src/pages/login.test.tsx`, `frontend/src/test-utils.tsx`
- Modify: `frontend/src/lib/api.ts`, `frontend/src/lib/api.test.ts`, `frontend/src/App.tsx`, `frontend/src/components/layout/AppLayout.tsx`, `frontend/src/locales/{ar,en}.json`, `frontend/src/pages/{employees,sites}.test.tsx` (import the moved helpers)

**Interfaces:**
- Consumes: Task 1 `POST /auth/login`, `POST /auth/logout`, `GET /auth/me`; Task 2 `permissions` on the profile; the existing `ApiError` and `api`/`apiList` in `src/lib/api.ts`.
- Produces:
  - `api.ts`:
    - `getToken(): string | null` and `setToken(token: string | null): void`, persisted in localStorage key `hrms.auth.token.v1` with try/catch, as `src/i18n.ts` does.
    - `setUnauthorizedHandler(fn: () => void): void`.
    - Every call sends `Authorization: Bearer <token>` when a token exists, and `Accept-Language: i18n.language`.
    - A 401 **while a token was sent** clears the token and calls the handler. A 401 from login itself (no token) only throws.
  - `auth.tsx`:
    - `type Me = { id: string; name: string; email: string; role: { id: string; slug: string; name: string; name_ar: string }; permissions: string[] }`.
    - `AuthProvider`; `export const AuthContext` (so tests can provide a fixed value); `export function canDo(permissions: string[], ability: string): boolean` (the matcher `can` uses).
    - `useAuth(): { me: Me | null; loading: boolean; login(email: string, password: string): Promise<void>; logout(): Promise<void>; can(ability: string): boolean }`. The context default (outside a provider) is `{ me: null, loading: false, can: () => false, … }`, so existing page tests need no provider.
    - `can('employee.view')` is true when any permission starts with `employee.view.`. `can('employee.view.all')` is an exact match.
    - `RequireAuth`: no token → `<Navigate to="/login" replace />`; while `me` loads → a translated loading line.
  - `LoginPage` at `/login`, outside `AppLayout`, with the language toggle (reuse `SegmentedControl`).
  - `AppLayout`:
    - Each `NAV` item gains `ability` (`employee.view` for employees; `site.view` for sites, residences and fares). Items the user `can`not are hidden.
    - The header shows `me.name` and a logout button.
    - `setUnauthorizedHandler` navigates to `/login`.
  - i18n keys with exact copy (ar / en):
    - `auth.email`: البريد الإلكتروني / Email
    - `auth.password`: كلمة المرور / Password
    - `auth.submit`: دخول / Sign in
    - `auth.logout`: خروج / Sign out
    - `auth.loading`: جارٍ التحميل… / Loading…
    - `auth.error.invalid_credentials`: البريد الإلكتروني أو كلمة المرور غير صحيحة. / Wrong email or password.
    - `auth.error.account_locked`: الحساب مقفل بعد محاولات فاشلة كثيرة. تواصل مع المطور. / The account is locked after too many failed attempts. Contact the developer.
    - `auth.error.account_suspended`: الحساب معطّل. تواصل مع المطور. / The account is deactivated. Contact the developer.
    - `auth.error.rate_limited`: محاولات كثيرة، حاول بعد دقيقة. / Too many attempts; try again in a minute.
    - `auth.error.unknown`: تعذّر تسجيل الدخول. / Could not sign in.

- [ ] **Step 1: Write the failing tests**

`api.test.ts` (append):
```ts
test('sends the bearer token and the UI language', async () => {
  const f = vi.fn(async () => new Response(JSON.stringify({ data: {} }), { status: 200 }))
  vi.stubGlobal('fetch', f)
  setToken('a'.repeat(64))
  await api('/auth/me')
  const headers = (f.mock.calls[0] as unknown as [string, RequestInit])[1].headers as Record<string, string>
  expect(headers.Authorization).toBe(`Bearer ${'a'.repeat(64)}`)
  expect(headers['Accept-Language']).toBe('ar')
})

test('a 401 with a token clears it and calls the handler; without a token it only throws', async () => {
  const onUnauthorized = vi.fn()
  setUnauthorizedHandler(onUnauthorized)
  reply(401, { error: { code: 'unauthenticated', message: 'x' } })
  setToken('a'.repeat(64))
  await api('/auth/me').catch(() => {})
  expect(getToken()).toBeNull()
  expect(localStorage.getItem('hrms.auth.token.v1')).toBeNull()
  expect(onUnauthorized).toHaveBeenCalledTimes(1)
  await api('/auth/login', { method: 'POST', body: {} }).catch(() => {})
  expect(onUnauthorized).toHaveBeenCalledTimes(1)
})
```

`login.test.tsx`:
```tsx
test('signs in, stores the token and opens the app', async () => {
  mockFetch({
    'POST /api/v1/auth/login': () => json(201, { data: { token: 't'.repeat(64), user: me(['employee.view.all']) } }),
    'GET /api/v1/auth/me': () => json(200, { data: me(['employee.view.all']) }),
    'GET /api/v1/employees': () => json(200, { data: [], meta: { pagination } }),
  })
  renderApp('/login')
  await user.type(screen.getByLabelText('البريد الإلكتروني'), 'hr@smart.test')
  await user.type(screen.getByLabelText('كلمة المرور'), 'secret123')
  await user.click(screen.getByRole('button', { name: 'دخول' }))
  expect(await screen.findByRole('link', { name: 'الموظفون' })).toBeInTheDocument()
  expect(localStorage.getItem('hrms.auth.token.v1')).toBe('t'.repeat(64))
})

test('shows the translated refusal', async () => {
  mockFetch({ 'POST /api/v1/auth/login': () => json(423, { error: { code: 'account_locked', message: 'x' } }) })
  renderApp('/login')
  // fill + submit as above
  expect(await screen.findByRole('alert')).toHaveTextContent('الحساب مقفل بعد محاولات فاشلة كثيرة. تواصل مع المطور.')
})

test('without a token any page redirects to login', async () => {
  renderApp('/employees')
  expect(await screen.findByRole('button', { name: 'دخول' })).toBeInTheDocument()
})

test('nav hides what the user cannot do', async () => {
  localStorage.setItem('hrms.auth.token.v1', 't'.repeat(64))
  mockFetch({ 'GET /api/v1/auth/me': () => json(200, { data: me(['site.view.own']) }), 'GET /api/v1/sites': () => json(200, { data: [], meta: { pagination } }) })
  renderApp('/sites')
  expect(await screen.findByRole('link', { name: 'المواقع' })).toBeInTheDocument()
  expect(screen.queryByRole('link', { name: 'الموظفون' })).toBeNull()
})
```
Create `frontend/src/test-utils.tsx` exporting `json`, `mockFetch` and `pagination` (moved from `employees.test.tsx` and `sites.test.tsx`, which then import them), plus `me(permissions: string[]): Me` and `renderApp(path)`. `renderApp` renders the app routes inside `AuthProvider` + `MemoryRouter initialEntries={[path]}`; to do that, export the route tree from `App.tsx` as `AppRoutes` and keep `BrowserRouter` in `App`. Nav labels are the existing `ar.json` ones (`الموظفون`, `المواقع`).

- [ ] **Step 2: Run the tests and see them fail**

Run: `cd frontend && npx vitest run src/lib/api.test.ts src/pages/login.test.tsx`
Expected: FAIL (`setToken` not exported; `/login` route missing).

- [ ] **Step 3: Implement the `api.ts` changes, `auth.tsx`, `LoginPage`, `AppRoutes` (`/login` outside the layout; the layout wrapped in `RequireAuth`) and the `AppLayout` changes**

The login form maps `ApiError.code` to `auth.error.<code>`, falling back to `auth.error.unknown`. Error text renders in a `role="alert"` element.

- [ ] **Step 4: Run the tests and see them pass**

Run: `cd frontend && npm test && npm run build && npm run lint`
Expected: PASS, including the locale key-parity test and the 1a page tests.

- [ ] **Step 5: Commit**

```bash
git add frontend
git commit -m "Add login screen, bearer session and permission-aware navigation"
```

---

### Task 7: Developer console (accounts, roles, audit log)

**Files:**
- Create: `frontend/src/pages/console.tsx` (`AccountsPage`, `RolesPage`), `frontend/src/pages/audit.tsx` (`AuditPage`), `frontend/src/pages/console.test.tsx`, `frontend/src/pages/audit.test.tsx`
- Modify: `frontend/src/test-utils.tsx` (add `renderWithAuth`), `frontend/src/components/form.tsx` (receive `FormDialog`, `FormBody`, `useList` moved out of `src/pages/sites.tsx`, exported), `frontend/src/pages/sites.tsx` (import them instead), `frontend/src/App.tsx`, `frontend/src/components/layout/AppLayout.tsx`, `frontend/src/locales/{ar,en}.json`

**Interfaces:**
- Consumes: Task 4 routes (`users`, `roles`, `permissions`); Task 3 `GET /audit-log`; Task 6 `useAuth().can`; `Field`, `TextField`, `SelectField`, `ErrorBanner`, `toErrs` from `src/components/form.tsx`; `Dialog*` and `Table*` from `src/components/ui`.
- Produces:
  - Routes `/console/accounts` (nav ability `user.view`), `/console/roles` (`role.view`), `/console/audit` (`audit_log.view`). They form a nav group headed `nav.console` (ar `لوحة المطور`, en `Developer console`).
  - `AccountsPage`:
    - Table: name, email, role (`name_ar` in Arabic, `name` in English), active badge, and a locked badge when `locked_until` is in the future.
    - Shown only with `can('user.manage')`:
      - Create dialog (name, email, role select, password).
      - Edit dialog (name, email, role).
      - Deactivate/reactivate button.
      - Set-password dialog.
      - Sessions dialog (IP, user agent, last activity, revoke button per row).
  - `RolesPage`:
    - Role list; selecting a role shows the permission matrix.
    - Matrix rows: one per resource (`employee`, `site`, `user`, `role`, `audit_log`). Cells: one checkbox per permission of that resource, labelled `perm.<resource>.<action>` + `scope.<scope>`.
    - Save sends `PATCH /roles/{id}/permissions {permission_ids}`; a 422 shows in the `ErrorBanner`.
    - Create-role dialog (slug, name, name_ar).
    - Editing needs `can('role.manage')`; otherwise the checkboxes are disabled.
  - `AuditPage`:
    - Filters: actor (select from `GET /users?per_page=100`, shown only with `can('user.view')`: the CEO reads the log without account access), entity type (select over the known table names), from/to (`<input type="date">`).
    - Paginated table: time, actor name, event, entity type, entity id, and a `<details>` with old/new JSON in `<pre dir="ltr">`.
    - Time shows in `Africa/Cairo` via `Intl.DateTimeFormat(i18n.language, { timeZone: 'Africa/Cairo', dateStyle: 'short', timeStyle: 'medium' })`.
  - i18n:
    - `perm.<resource>.<action>`, `scope.own` (ar `موظفوه فقط` / en `own`), `scope.all` (ar `الكل` / en `all`).
    - `audit.event.<EVENT>` for `CREATED`, `UPDATED`, `DELETED`, every `IdentityAuditEvents` constant, and `SITE_MANAGERS_UPDATED`.
    - `audit.entity.<table>` for the 9 1a tables plus `users`, `roles`, `employee_manager_assignments`.

- [ ] **Step 1: Write the failing tests**

`console.test.tsx`:
```tsx
test('role matrix saves the checked permission ids', async () => {
  const patch = vi.fn(() => json(200, { data: { ...role, permissions: ['employee.view.own', 'site.view.own', 'audit_log.view.all'] } }))
  mockFetch({ 'GET /api/v1/roles': () => json(200, { data: [role] }), 'GET /api/v1/permissions': () => json(200, { data: perms }), 'PATCH /api/v1/roles/r1/permissions': patch })
  renderWithAuth(<RolesPage />, ['role.view.all', 'role.manage.all'])
  await user.click(await screen.findByRole('button', { name: 'مدير' }))
  await user.click(screen.getByRole('checkbox', { name: /سجل التدقيق.*عرض.*الكل/ }))
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  expect(JSON.parse((patch.mock.calls[0] as unknown as [string, RequestInit])[1].body as string).permission_ids.sort()).toEqual(['p-audit', 'p-emp-own', 'p-site-own'])
})

test('account create shows a server field error under its field', async () => {
  mockFetch({ 'GET /api/v1/users': () => json(200, { data: [], meta: { pagination } }), 'GET /api/v1/roles': () => json(200, { data: [role] }),
    'POST /api/v1/users': () => json(422, { error: { code: 'validation_failed', message: 'x', details: [{ field: 'email', code: 'invalid', message: 'البريد مستخدم' }] } }) })
  renderWithAuth(<AccountsPage />, ['user.view.all', 'user.manage.all'])
  // open create dialog, fill, save
  expect(await screen.findByText('البريد مستخدم')).toBeInTheDocument()
})

test('without user.manage there are no account actions', async () => {
  mockFetch({ 'GET /api/v1/users': () => json(200, { data: [account], meta: { pagination } }), 'GET /api/v1/roles': () => json(200, { data: [role] }) })
  renderWithAuth(<AccountsPage />, ['user.view.all'])
  expect(await screen.findByText(account.email)).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'إضافة حساب' })).toBeNull()
})
```
`audit.test.tsx`:
```tsx
test('filters become the query string and times show in Cairo', async () => {
  const list = vi.fn(() => json(200, { data: [{ id: 'a1', event: 'CREATED', entity_type: 'sites', entity_id: 's1', occurred_at: '2026-10-16T22:30:00Z', user: { id: 'u1', name: 'منى' }, old_values: null, new_values: { name_ar: 'سموحة' } }], meta: { pagination } }))
  mockFetch({ 'GET /api/v1/audit-log': list, 'GET /api/v1/users': () => json(200, { data: [{ id: 'u1', name: 'منى' }], meta: { pagination } }) })
  renderWithAuth(<AuditPage />, ['audit_log.view.all', 'user.view.all'])
  await i18n.changeLanguage('en')
  expect(await screen.findByText(/10\/17\/26, 1:30:00/)).toBeInTheDocument()
  await user.selectOptions(screen.getByLabelText('Actor'), 'u1')
  await user.type(screen.getByLabelText('From'), '2026-10-17')
  await waitFor(() => expect(String(list.mock.lastCall?.[0])).toContain('user_id=u1'))
  expect(String(list.mock.lastCall?.[0])).toContain('from=2026-10-17')
})
```
Add `renderWithAuth(ui, permissions)` to `src/test-utils.tsx`: it wraps `ui` in a `MemoryRouter` and the exported `AuthContext.Provider` from `auth.tsx`, with a value whose `me` is `me(permissions)` and whose `can` is `(a) => canDo(permissions, a)`. Fixture `perms` are the 15 seeded permissions with ids such as `p-audit`, `p-emp-own`, `p-site-own`; `role` is `{ id: 'r1', slug: 'manager', name: 'Manager', name_ar: 'مدير', permissions: ['employee.view.own', 'site.view.own'] }`. Required copy: `console.addAccount` ar `إضافة حساب`; `audit.actor` en `Actor`; `audit.from` en `From`; `perm.audit_log.view` ar `سجل التدقيق: عرض`.

- [ ] **Step 2: Run the tests and see them fail**

Run: `cd frontend && npx vitest run src/pages/console.test.tsx src/pages/audit.test.tsx`
Expected: FAIL (pages missing).

- [ ] **Step 3: Move `FormDialog`, `FormBody` and `useList` from `sites.tsx` to `form.tsx` (export them); run `npm test` and confirm the sites tests still pass**

- [ ] **Step 4: Implement `AccountsPage`, `RolesPage`, `AuditPage`, routes, nav group and locale keys**

- [ ] **Step 5: Run the tests and see them pass**

Run: `cd frontend && npm test && npm run build && npm run lint`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend
git commit -m "Add developer console: accounts, role permissions and audit log"
```

---

### Task 8: Manager assignment screens

**Files:**
- Modify: `frontend/src/test-utils.tsx` (`renderWithAuth` option `{ router: false }`), `frontend/src/pages/employees.tsx`, `frontend/src/pages/employees.test.tsx`, `frontend/src/pages/sites.tsx`, `frontend/src/pages/sites.test.tsx`, `frontend/src/locales/{ar,en}.json`

**Interfaces:**
- Consumes: Task 5 `current_manager`, `manager_assignments`, `POST /employees/{id}/manager-assignments`, site `managers`, `PUT /sites/{id}/managers`; Task 4 `GET /users?active=true&per_page=100`; Task 6 `useAuth().can`; Task 7 `FormDialog` from `form.tsx`.
- Produces:
  - On the employee detail page, a "Manager" section beside the type and pay history. It shows the current manager (or `manager.none`) and the history table (manager name, effective from). With `can('employee.assign_manager')` it also shows an "Assign manager" dialog: a manager select from active users plus a date, posting `{manager_id, effective_from}`; server errors show under their fields.
  - On the sites list, a per-row "Managers" dialog (`can('site.assign_manager')`): checkboxes over active users, pre-checked from the site's `managers`, saving `PUT /sites/{id}/managers {manager_ids}`.
  - Copy: `manager.title` ar `المدير` / en `Manager`; `manager.assign` ar `تعيين مدير` / en `Assign manager`; `manager.none` ar `بدون مدير` / en `No manager`; `site.managers` ar `المديرون` / en `Managers`.

- [ ] **Step 1: Write the failing tests**

```tsx
test('assigning a manager posts the chosen user and date', async () => {
  const post = vi.fn(() => json(201, { data: { ...emp, current_manager: { id: 'u1', name: 'خالد' } } }))
  mockFetch({ 'GET /api/v1/employees/e1': () => json(200, { data: emp }), 'GET /api/v1/residences': residences,
    'GET /api/v1/users': () => json(200, { data: [{ id: 'u1', name: 'خالد', is_active: true }], meta: { pagination } }),
    'POST /api/v1/employees/e1/manager-assignments': post })
  renderWithAuth(<EmployeeDetailRoute id="e1" />, ['employee.view.all', 'employee.assign_manager.all'])
  await user.click(await screen.findByRole('button', { name: 'تعيين مدير' }))
  await user.selectOptions(screen.getByLabelText('المدير'), 'u1')
  await user.type(screen.getByLabelText('ساري من'), '2026-11-01')
  await user.click(screen.getByRole('button', { name: 'حفظ' }))
  expect(JSON.parse((post.mock.calls[0] as unknown as [string, RequestInit])[1].body as string)).toEqual({ manager_id: 'u1', effective_from: '2026-11-01' })
  expect(await screen.findByText('خالد')).toBeInTheDocument()
})

test('without the permission there is no assign button', async () => {
  mockFetch({ 'GET /api/v1/employees/e1': () => json(200, { data: { ...emp, current_manager: null } }), 'GET /api/v1/residences': residences })
  renderWithAuth(<EmployeeDetailRoute id="e1" />, ['employee.view.own'])
  expect(await screen.findByText('بدون مدير')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'تعيين مدير' })).toBeNull()
})
```
`EmployeeDetailRoute` is a local helper in `employees.test.tsx`: `<MemoryRouter initialEntries={[`/employees/${id}`]}><Routes><Route path="employees/:id" element={<EmployeeFormPage />} /></Routes></MemoryRouter>`, rendered through `renderWithAuth` (whose own `MemoryRouter` it then replaces: give `renderWithAuth` an optional `{ router: false }`). `emp` is `{ id: 'e1', name_ar: 'أحمد', current_type: 'office', type_changes: [], pay_changes: [], documents: [], current_manager: null, manager_assignments: [] }` (the shape the file already uses at line 113, plus the two manager keys).
In `sites.test.tsx`: the managers dialog pre-checks the site's current managers, and saving sends `{ manager_ids: ['u1', 'u2'] }` after checking `u2`. Reuse the existing effective-date label key from the 1a pay dialog for `ساري من` (check `ar.json`).

- [ ] **Step 2: Run the tests and see them fail**

Run: `cd frontend && npx vitest run src/pages/employees.test.tsx src/pages/sites.test.tsx`
Expected: FAIL.

- [ ] **Step 3: Implement the manager section, the assign dialog, the site managers dialog and the locale keys**

- [ ] **Step 4: Run the tests and see them pass**

Run: `cd frontend && npm test && npm run build && npm run lint`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend
git commit -m "Add manager assignment to employee and site screens"
```

---

### Task 9: Arabic API messages chosen by Accept-Language

**Files:**
- Create: `backend/app/Http/Middleware/SetLocaleFromRequest.php` (copy of the CRM's)
- Create: `backend/lang/ar/validation.php` (the CRM's Arabic messages, with `attributes` replaced by the HRMS field names), `backend/lang/en/validation.php` (only `attributes`; Laravel merges it over the framework's English file), `backend/lang/{en,ar}/errors.php`
- Modify: `backend/bootstrap/app.php` (prepend the middleware after `AddRequestId`; envelope messages through `__('errors.*')`), `backend/phpunit.xml` (`<env name="APP_LOCALE" value="ar" force="true"/>`), and the ten literal `ValidationException::withMessages` strings in `EmployeeService`, `SiteService`, `FareService` (→ `__('errors.<key>')`)
- Test: `backend/tests/Feature/LocaleTest.php`

**Interfaces:**
- Consumes: every earlier task's messages.
- Produces:
  - Locale = the first of `ar`/`en` in `Accept-Language`, else `config('app.locale')` (`ar`). The response carries `Content-Language` and `Vary: Accept-Language`.
  - `errors.php` keys:
    - Envelope: `validation_failed`, `not_found`, `unauthenticated`, `server_error`.
    - Rule messages: `bank_account_required`, `pay_field_required` (`:field`, `:type`), `no_type_on_date`, `duplicate_date`, `leave_code_required`, `leave_code_not_allowed`, `allowance_value_required`.
  - `validation.attributes` (ar and en) covers every request field in the API: the 1a employee, site, fare and rule fields plus `email`, `password`, `role_id`, `name`, `name_ar`, `slug`, `permission_ids`, `manager_id`, `manager_ids`. Arabic labels must match the frontend's `ar.json` wording for the same field.

- [ ] **Step 1: Write the failing tests** (`tests/Feature/LocaleTest.php`, `use RefreshDatabase`, `signIn('hr')` in `setUp`)

```php
public function test_validation_messages_follow_accept_language(): void
{
    $this->withHeader('Accept-Language', 'ar')->postJson('/api/v1/employees', ['gender' => 'male', 'type' => 'office', 'effective_from' => '2026-01-01', 'salary' => '1'])
        ->assertStatus(422)->assertJsonPath('error.details.0.message', 'حقل الاسم بالعربية مطلوب.')
        ->assertHeader('Content-Language', 'ar');
    $this->withHeader('Accept-Language', 'en-US,en;q=0.9')->postJson('/api/v1/employees', ['gender' => 'male', 'type' => 'office', 'effective_from' => '2026-01-01', 'salary' => '1'])
        ->assertJsonPath('error.details.0.message', 'The Arabic name field is required.')
        ->assertJsonPath('error.message', 'The given data was invalid.');
}

public function test_unsupported_or_missing_language_falls_back_to_arabic(): void
{
    $this->withHeader('Accept-Language', 'fr')->getJson('/api/v1/employees/'.Str::uuid7())->assertNotFound()->assertHeader('Content-Language', 'ar');
    $this->getJson('/api/v1/employees/'.Str::uuid7())->assertJsonPath('error.message', 'المورد غير موجود.');
}

public function test_service_rule_messages_are_translated(): void
{
    $this->withHeader('Accept-Language', 'ar')->postJson('/api/v1/employees', ['name_ar' => 'أ', 'gender' => 'male', 'type' => 'office', 'effective_from' => '2026-01-01', 'salary' => '1', 'pay_method' => 'bank'])
        ->assertJsonPath('error.details.0.message', 'رقم الحساب البنكي مطلوب عند الصرف عن طريق البنك.');
}

public function test_identity_refusals_follow_accept_language(): void
{
    $this->withHeader('Accept-Language', 'en')->postJson('/api/v1/auth/login', ['email' => 'x@smart.test', 'password' => 'x'])
        ->assertStatus(401)->assertJsonPath('error.message', 'These credentials do not match our records.');
}
```

Fixed copy:
- `validation.attributes.name_ar`: ar `الاسم بالعربية`, en `Arabic name`.
- `errors.not_found`: ar `المورد غير موجود.`, en `Resource not found.`
- `errors.validation_failed`: en `The given data was invalid.`, ar `البيانات المُرسلة غير صحيحة.`
- `errors.bank_account_required`: ar `رقم الحساب البنكي مطلوب عند الصرف عن طريق البنك.`, en `Bank account is required when paying by bank.`
- `identity.refusal.invalid_credentials`: en `These credentials do not match our records.`

- [ ] **Step 2: Run the tests and see them fail**

Run: `docker compose exec app php artisan test --filter=LocaleTest`
Expected: FAIL (English framework messages, no `Content-Language`).

- [ ] **Step 3: Add the middleware, the lang files, the `phpunit.xml` env line; switch the bootstrap envelope messages and the ten service literals to `__()` keys**

- [ ] **Step 4: Run the tests and see them pass**

Run: `docker compose exec app php artisan test`
Expected: PASS. Every earlier test asserts codes and fields, not English text, so none should change. If one does, fix the test to send `Accept-Language: en` rather than changing the message. Then run every gate.

- [ ] **Step 5: Commit**

```bash
git add backend
git commit -m "Serve Arabic or English API messages from Accept-Language"
```
