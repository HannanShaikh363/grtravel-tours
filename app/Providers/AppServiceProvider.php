<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use ProtoneMedia\Splade\Components\Form\Select;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Jobs\LogQueryJob;


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
        //
        $this->app->bind('path.public', function () {
            return base_path('public');
        });



    }
}
