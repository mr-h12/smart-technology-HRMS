<?php

namespace Tests\Unit;

use App\Modules\Identity\Domain\Authentication\IdleTimeout;
use App\Modules\Identity\Domain\Authentication\LockoutPolicy;
use App\Modules\Identity\Domain\Authentication\SessionToken;
use App\Modules\Identity\Domain\Rbac\PermissionDecision;
use App\Modules\Identity\Domain\Rbac\Scope;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class IdentityPoliciesTest extends TestCase
{
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

    public function test_scope_and_decision(): void
    {
        $this->assertTrue(Scope::All->includes(Scope::Own));
        $this->assertFalse(Scope::Own->includes(Scope::All));
        $this->assertFalse((new PermissionDecision([]))->granted());
        $this->assertTrue((new PermissionDecision([Scope::Own]))->allows(Scope::Own));
        $this->assertFalse((new PermissionDecision([Scope::Own]))->allows(Scope::All));
    }
}
