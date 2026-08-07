<?php

namespace App\Providers;

use App\Models\StudyMaterial;
use App\Observers\StudyMaterialObserver;
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
        StudyMaterial::observe(StudyMaterialObserver::class);
    }
}
