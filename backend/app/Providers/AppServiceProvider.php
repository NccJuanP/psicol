<?php

namespace App\Providers;

use App\Domain\Audit\Listeners\RecordIncidentActivity;
use App\Domain\Incidents\Events\IncidentEvent;
use App\Domain\Incidents\Models\Incident;
use App\Infrastructure\Broadcasting\Listeners\BroadcastIncidentChange;
use App\Policies\IncidentPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
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

        // D-22: lazy loading, missing attributes and discarded attributes throw outside production.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Paginated<T> in docs/api-contract.md: Laravel's meta also carries path and per-page links.
        JsonResource::macro('paginationInformation', fn (Request $request, array $paginated, array $default) => [
            'meta' => Arr::only($default['meta'], ['current_page', 'per_page', 'total', 'last_page', 'from', 'to']),
            'links' => $default['links'],
        ]);

        Gate::policy(Incident::class, IncidentPolicy::class);

        foreach (IncidentEvent::ALL as $event) {
            Event::listen($event, RecordIncidentActivity::class);  // P1, inside the transaction
            Event::listen($event, BroadcastIncidentChange::class); // P2 + P3, after commit
        }

        // R-16 for every {incident} route: outside the user's visibility it does not exist (404, not 403).
        Route::pattern('incident', '[0-9]+');
        Route::bind('incident', fn (string $id) => Incident::query()
            ->visibleTo(request()->user())
            ->findOrFail($id));
    }
}
