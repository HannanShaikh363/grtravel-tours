<?php

namespace App\Jobs;

use App\Models\City;
use App\Models\Hotel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class SyncTboHotels implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $cityCode;

    public function __construct(string $cityCode)
    {
        $this->cityCode = $cityCode;
    }

    public function handle()
    {
        try {

            $response = Http::withBasicAuth('globaltravl', 'Grt@17644905')
                ->post('http://api.tbotechnology.in/TBOHolidays_HotelAPI/TBOHotelCodeList', [
                    'CityCode' => $this->cityCode,
                    'IsDetailedResponse' => 'true'
                ]);

            if (!$response->successful()) {
                Log::error("API request failed", ['status' => $response->status()]);
                return;
            }

            $data = $response->json();
            
            if ($data['Status']['Code'] != 200) {
                Log::error("API error", ['response' => $data]);
                return;
            }

            $this->bulkUpsertHotels($data['Hotels'], $this->cityCode);

            Log::info("Hotels synced", [
                'city_id' => $this->cityCode,
                'processed' => count($data['Hotels'])
            ]);

        } catch (\Exception $e) {
            Log::error("Sync failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    protected function bulkUpsertHotels(array $tboHotels, int $cityId)
    {
        $now = now();
        $hotels = [];

        foreach ($tboHotels as $tboHotel) {
            $hotels[] = [
                'hotel_name' => $tboHotel['HotelName'],
                'city_id' => $cityId,
                'tbo_hotel_code' => $tboHotel['HotelCode'],
                'created_at' => $now,
                'updated_at' => $now
            ];
        }

        // Process in chunks to avoid huge queries
        foreach (array_chunk($hotels, 500) as $chunk) {
            Hotel::upsert(
                $chunk,
                ['tbo_hotel_code'], // Unique identifier
                ['hotel_name', 'city_id', 'updated_at'] // Fields to update
            );
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::error("Job failed", [
            'city_code' => $this->cityCode,
            'error' => $exception->getMessage()
        ]);
    }
}