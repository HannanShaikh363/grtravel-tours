<?php

namespace App\Jobs;

use App\Models\City;
use App\Models\Hotel;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessHotelCsv implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 600;
    public $backoff = [30, 60, 120];
    
    protected array $rows;
    protected int $chunkNumber;

    public function __construct(array $rows, int $chunkNumber)
    {
        $this->rows = $rows;
        $this->chunkNumber = $chunkNumber;
    }

    public function handle()
    {
        if ($this->batch() && $this->batch()->cancelled()) {
            return;
        }
        
        // Ensure database connection
        $this->ensureDatabaseConnection();
        
        // Extract city identifiers from all rows
        $cityIdentifiers = $this->extractCityIdentifiers();
        
        // Load all needed cities in one query
        $cities = $this->loadCities($cityIdentifiers);
        
        // Prepare hotel data
        $hotels = $this->prepareHotelData($cities);
        
        // Upsert in optimized chunks
        $this->upsertHotels($hotels);
    }
    
    protected function ensureDatabaseConnection()
    {
        $maxAttempts = 3;
        $attempt = 0;
        
        while ($attempt < $maxAttempts) {
            try {
                DB::connection()->getPdo()->query('SELECT 1')->execute();
                return;
            } catch (\Exception $e) {
                $attempt++;
                if ($attempt >= $maxAttempts) {
                    throw new \RuntimeException("Database connection failed after {$maxAttempts} attempts");
                }
                sleep(min(pow(2, $attempt), 10)); // Exponential backoff
                DB::reconnect();
            }
        }
    }
    
    protected function extractCityIdentifiers(): array
    {
        $identifiers = [];
        
        foreach ($this->rows as $row) {
            if (count($row) < 4) continue;
            
            [$_, $_, $cityName, $cityCode] = $row;
            $key = strtolower("{$cityCode}|{$cityName}");
            
            if (!isset($identifiers[$key])) {
                $identifiers[$key] = [
                    'code' => $cityCode,
                    'name' => $cityName
                ];
            }
        }
        
        return $identifiers;
    }
    
    protected function loadCities(array $identifiers): array
    {
        if (empty($identifiers)) return [];
        
        $codes = array_column($identifiers, 'code');
        $names = array_column($identifiers, 'name');
        
         $cities =  City::query()
            ->whereIn('rezlive_code', $codes)
            ->orWhereIn('name', $names)
            ->get()
            ->keyBy(function ($city) {
                return strtolower("{$city->rezlive_code}|{$city->name}");
            })
            ->toArray();

            // Fuzzy match fallback
            foreach ($identifiers as $key => $data) {
                if (!isset($cities[$key]) && str_contains($data['name'], ',')) {
                    $shortName = trim(explode(',', $data['name'])[0]);
                    $fuzzyCity = City::where('name', $shortName)->first();

                    if ($fuzzyCity) {
                        $fuzzyKey = strtolower("{$data['code']}|{$data['name']}");
                        $cities[$fuzzyKey] = $fuzzyCity->toArray();
                    }
                }
            }

        return $cities;
    }
    
    protected function prepareHotelData(array $cities): array
    {
        $hotels = [];
        $unmatched = [];

        
        foreach ($this->rows as $row) {
            if (count($row) < 4) continue;
            
            [$hotelCode, $hotelName, $cityName, $cityCode] = $row;
            $key = strtolower("{$cityCode}|{$cityName}");
            
            if (isset($cities[$key])) {
                $hotels[] = [
                    'rezlive_hotel_code' => $hotelCode,
                    'city_id' => $cities[$key]['id'],
                    'hotel_name' => $hotelName,
                    'created_at' => now(),
                    'updated_at' => now()
                ];
            }else{
                 $unmatched[] = $key;
            }
        }
        logger()->info("Unmatched rows count: " . count($unmatched));
        logger()->debug("Sample unmatched keys", array_slice($unmatched, 0, 10));
        return $hotels;
    }
    
    // protected function upsertHotels(array $hotels)
    // {
    //     if (empty($hotels)) return;
        
    //     // Process in chunks to avoid huge SQL statements
    //     foreach (array_chunk($hotels, 300) as $chunk) {
    //         Hotel::withoutTimestamps(function () use ($chunk) {
    //             Hotel::upsert(
    //                 $chunk,
    //                 ['rezlive_hotel_code', 'city_id'],
    //                 ['hotel_name', 'updated_at']
    //             );
    //         });
    //     }
    // }

    // protected function upsertHotels(array $hotels)
    // {
    //     if (empty($hotels)) return;

    //     $hotels = collect($hotels)->sortBy('rezlive_hotel_code')->values()->all();

    //     foreach (array_chunk($hotels, 100) as $chunk) {
    //         $attempts = 0;
    //         $maxAttempts = 3;

    //         while ($attempts < $maxAttempts) {
    //             try {
    //                 Hotel::withoutTimestamps(function () use ($chunk) {
    //                     Hotel::upsert(
    //                         $chunk,
    //                         ['rezlive_hotel_code', 'city_id'],
    //                         ['hotel_name', 'updated_at']
    //                     );
    //                 });
    //                 break;
    //             } catch (\Throwable $e) {
    //                 $attempts++;
    //                 logger()->warning('Upsert deadlock, retry attempt #' . $attempts, [
    //                     'error' => $e->getMessage()
    //                 ]);

    //                 if (
    //                     str_contains($e->getMessage(), 'Deadlock found') &&
    //                     $attempts < $maxAttempts
    //                 ) {
    //                     sleep(2);
    //                     continue;
    //                 }

    //                 throw $e;
    //             }
    //         }
    //     }
    // }

    protected function upsertHotels(array $hotels)
    {
        if (empty($hotels)) return;

        $hotels = collect($hotels)
            ->unique(fn($h) => $h['rezlive_hotel_code'] . '-' . $h['city_id'])
            ->sortBy('rezlive_hotel_code')
            ->values()
            ->all();

        foreach (array_chunk($hotels, 50) as $chunk) {
            $attempts = 0;
            $maxAttempts = 5;
            $sleepBetweenRetries = 2;

            while ($attempts < $maxAttempts) {
                try {
                    DB::transaction(function () use ($chunk) {
                        Hotel::withoutTimestamps(function () use ($chunk) {
                            Hotel::upsert(
                                $chunk,
                                ['rezlive_hotel_code', 'city_id'],
                                ['hotel_name', 'updated_at']
                            );
                        });
                    });

                    break; // success
                } catch (\Throwable $e) {
                    $attempts++;
                    logger()->warning("Upsert deadlock attempt #$attempts", [
                        'error' => $e->getMessage()
                    ]);

                    if (
                        str_contains($e->getMessage(), 'Deadlock found') &&
                        $attempts < $maxAttempts
                    ) {
                        sleep($sleepBetweenRetries);
                        continue;
                    }

                    throw $e; // rethrow if not deadlock or max attempts reached
                }
            }
        }
    }

    
    public function failed(Throwable $exception)
    {
        logger()->error("Hotel import chunk failed", [
            'chunk_number' => $this->chunkNumber,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}