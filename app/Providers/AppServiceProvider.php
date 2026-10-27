<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
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
        // Di produksi: migrate:fresh, db:wipe, migrate:reset dan sejenisnya ditolak supaya data klien tidak terhapus.
        DB::prohibitDestructiveCommands($this->app->isProduction());
    }
}
