<?php

namespace App\Jobs;

use App\Models\City;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessAllTboCities implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        try {
            // Get all cities that have TBO codes
            $cities = City::whereNotNull('tbo_code')
                        ->select(['id', 'tbo_code'])
                        ->cursor(); // Using cursor for memory efficiency
            
            $dispatchedCount = 0;

            foreach ($cities as $city) {
                // Use tbo_code if available, fallback to code
                $cityCode = $city->tbo_code;
                
                if (!empty($cityCode)) {
                    SyncTboHotels::dispatch($cityCode);
                    $dispatchedCount++;
                }
            }

            Log::info("Dispatched hotel sync jobs for cities", [
                'total_cities' => $dispatchedCount
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to process cities", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::error("ProcessAllTboCities job failed", [
            'error' => $exception->getMessage()
        ]);
    }
}