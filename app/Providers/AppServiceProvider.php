<?php

namespace App\Providers;

use App\Models\User;
use App\Support\PickupPushGateway;
use App\Support\PushGateway;
use App\Support\WebPushGateway;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PushGateway::class, WebPushGateway::class);
        $this->app->bind(PickupPushGateway::class, WebPushGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureCustomerExperienceRateLimits();

        Gate::define('products.manage', function (User $user): bool {
            $user = $user->exists ? User::query()->whereKey($user->getKey())->first() : null;

            return $user !== null && $user->is_active && $user->hasPermission('products.manage');
        });

        /**
         * The shared Product definitions (Product identity, image, Category and Modifier Groups) affect every Branch, so
         * only business-wide Product management edits them. A Branch-scoped products.manage manages its Branch
         * assortment and configuration only (UpsertBranchProduct, ConfigureBranchAssortment).
         */
        Gate::define('catalog.define', function (User $user): bool {
            $user = $user->exists ? User::query()->whereKey($user->getKey())->first() : null;

            return $user !== null && $user->is_active && $user->hasPermission('products.manage') && $user->hasBusinessWideScope();
        });

        Gate::define('inventory.manage', function (User $user): bool {
            $user = $user->exists ? User::query()->whereKey($user->getKey())->first() : null;

            return $user !== null && $user->is_active && $user->hasPermission('inventory.manage');
        });
    }

    /**
     * Phase 19.6 limits. Each is a named limiter with its own counter: an un-named `throttle:X,Y` shares one counter per
     * account (or IP) with every other un-named throttle in the app, so a busy live cart must never consume the budget
     * of a Void or a Store close. Public pages are limited per IP (and per pickup link for its writes).
     */
    protected function configureCustomerExperienceRateLimits(): void
    {
        $account = fn (Request $request): string => (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());

        RateLimiter::for('pos-customer-screen', fn (Request $request) => Limit::perMinute(240)->by($account($request)));
        RateLimiter::for('pos-customer-screen-pairing', fn (Request $request) => Limit::perMinute(10)->by($account($request)));
        RateLimiter::for('pickup-buzz', fn (Request $request) => Limit::perMinute(30)->by($account($request)));
        RateLimiter::for('customer-screen-media', fn (Request $request) => Limit::perMinute(60)->by($account($request)));
        RateLimiter::for('customer-screen', fn (Request $request) => Limit::perMinute(120)->by((string) $request->ip()));
        RateLimiter::for('customer-screen-pairing-code', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));
        RateLimiter::for('pickup', fn (Request $request) => Limit::perMinute(120)->by((string) $request->ip()));
        RateLimiter::for('pickup-subscription', fn (Request $request) => [
            Limit::perMinute(10)->by('link|'.sha1((string) $request->route('token'))),
            Limit::perMinute(30)->by('ip|'.$request->ip()),
        ]);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
