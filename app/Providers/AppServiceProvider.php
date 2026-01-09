<?php

namespace App\Providers;

use App\Services\ThemeService;
use App\View\Composers\ThemeComposer;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ThemeService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer(['home', 'post', 'errors.*'], ThemeComposer::class);
    }
}
