<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestCron extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:test-cron';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'For testing';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $time = Carbon::now();
        Log::info("Test Cron Job starting at: $time");
    }
}
