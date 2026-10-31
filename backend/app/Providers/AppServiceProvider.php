<?php

namespace App\Providers;

use App\Domain\Audit\Listeners\RecordIncidentActivity;
use App\Domain\Incidents\Events\IncidentEvent;
use App\Domain\Incidents\Models\Incident;
use App\Policies\IncidentPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));

        Gate::policy(Incident::class, IncidentPolicy::class);

        foreach (IncidentEvent::ALL as $event) {
            Event::listen($event, RecordIncidentActivity::class);
        }

        // R-16 for every {incident} route: outside the user's visibility it does not exist (404, not 403).
        Route::pattern('incident', '[0-9]+');
        Route::bind('incident', fn (string $id) => Incident::query()
            ->visibleTo(request()->user())
            ->findOrFail($id));
    }
}
