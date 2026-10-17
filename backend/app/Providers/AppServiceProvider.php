<?php

namespace App\Providers;

use App\Modules\Identity\Infrastructure\BearerSessionResolver;
use App\Modules\Identity\Infrastructure\RolePermissions;
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
        //
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
