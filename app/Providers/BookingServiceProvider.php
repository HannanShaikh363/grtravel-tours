<?php

namespace App\Providers;

use App\Services\BookingService;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class BookingServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // View::composer(
        //     ['web.agent.partials.listItem'], // Views you want to share data with
        //     function ($view) {
        //         // Use the BookingService to get the data
        //         $bookingService = app(BookingService::class);

        //         // Fetch the data you want to share across views
        //         $bookingData = $bookingService->getBookingData(request());

        //         // Share the data with the views
        //         $view->with($bookingData);
        //     }
        // );
    }
}
