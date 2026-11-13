<?php

namespace App\Providers;

use App\Modules\Documents\Domain\FormRenderer;
use App\Modules\Documents\Infrastructure\BrowsershotRenderer;
use App\Modules\Employees\Application\EmployeeOpenItems;
use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use App\Modules\Identity\Infrastructure\BearerSessionResolver;
use App\Modules\Identity\Infrastructure\RolePermissions;
use App\Modules\Leave\Application\LeaveRequests;
use App\Modules\Settings\Application\BusinessRules;
use App\Support\Database\StandardColumns;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(FormRenderer::class, BrowsershotRenderer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        StandardColumns::register();

        // Migrations write grants and settings straight to the tables, past the services that clear the caches.
        Event::listen(MigrationsEnded::class, function (): void {
            RolePermissions::forgetAll();
            BusinessRules::forgetCache();
        });

        // Leave vetoes an employee delete, so Employees never depends on Leave (D4).
        Employee::deleting(fn (Employee $e) => app(LeaveRequests::class)->assertNoOpenRequests((string) $e->getKey()));

        // Leave's open requests hold back a termination date, the same way (owner, 2026-11-11).
        Event::listen(EmployeeOpenItems::class, fn (EmployeeOpenItems $e) => app(LeaveRequests::class)->openRequests($e->employeeId));

        Auth::viaRequest('hrms-bearer-session', fn (Request $r) => app(BearerSessionResolver::class)->forRequest($r));

        // Per IP and address: one office behind one NAT cannot be throttled by one person's typos.
        RateLimiter::for('login', function (Request $r): Limit {
            $email = $r->input('email');

            return Limit::perMinutes(
                config()->integer('identity.rate_limit.login.decay_minutes'),
                config()->integer('identity.rate_limit.login.attempts'),
            )->by($r->ip().'|'.(is_string($email) ? mb_strtolower($email) : ''));
        });
    }
}
