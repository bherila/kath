<?php

namespace App\Providers;

use App\Support\WeddingGuest;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Spatie\Csp\AddCspHeaders;

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
        // Register the Spatie CSP middleware globally if the HTTP kernel is available.
        if ($this->app->bound(Kernel::class)) {
            $this->app->make(Kernel::class)
                ->pushMiddleware(AddCspHeaders::class);
        }

        $this->configureWeddingRateLimits();
    }

    /**
     * Named limiters, each with its own key. The numeric `throttle:N,M` form
     * keys every guest request by IP alone, so all such routes share one
     * counter — and wedding guests on venue Wi-Fi share one IP.
     */
    private function configureWeddingRateLimits(): void
    {
        // Generous per IP: a whole venue may enter from the same address.
        RateLimiter::for('wedding-enter', fn (Request $request): Limit => Limit::perMinute(120)
            ->by('wedding-enter|'.$request->ip()));

        // Per guest session: one large video signs a part per 16 MB.
        RateLimiter::for('wedding-uploads', function (Request $request): Limit {
            $guest = WeddingGuest::fromSession($request->session());

            return Limit::perMinute(600)->by('wedding-uploads|'.($guest?->tokenHash() ?? $request->ip()));
        });
    }
}
