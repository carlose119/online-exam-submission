<?php

namespace App\Providers;

use App\Models\StudyMaterial;
use App\Observers\StudyMaterialObserver;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

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
        StudyMaterial::observe(StudyMaterialObserver::class);

        RateLimiter::for('student-password-change', function (Request $request): Limit {
            return Limit::perMinute(6)->by($request->user()->getAuthIdentifier().'|'.sha1((string) $request->ip()));
        });

        Livewire::addPersistentMiddleware([
            AuthenticateSession::class,
            EnsureEmailIsVerified::class,
        ]);
    }
}
