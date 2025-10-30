<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Services\CurrencyService;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // $schedule->command('inspire')->hourly();

        $schedule->command('currency:fetch-rates')->daily();
        $schedule->command('send:pending-payment-reminders')->daily();
        $schedule->command('bookings:cancel-expired')->hourly();
        $schedule->command('bookings:cancel-expired-tour-bookings')->hourly();
        $schedule->command('adjustments:expire')->hourly();
        $schedule->command('app:test-cron')->daily();
        $schedule->command('hotel-booking-confirmation')->hourly();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
