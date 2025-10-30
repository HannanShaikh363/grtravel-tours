<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use App\Models\City;
use App\Models\Country; 

class TboCityCodeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    // public function run()
    // {
    //     $apiUrl = "http://api.tbotechnology.in/TBOHolidays_HotelAPI/CountryList";

    //     $username = config('services.tbo.username');
    //     $password = config('services.tbo.password');

    //     $countries = Country::select('id','iso2')->get();
    //     $this->command->info("Starting TBO City Code Seeder...");
    //     DB::statement('SET FOREIGN_KEY_CHECKS=0;');
    //     foreach ($countries as $country) {

    //         $response = Http::withHeaders([
    //             'Accept' => 'application/json',
    //             'Content-Type' => 'application/json',
    //         ])->withBasicAuth($username, $password)
    //           ->post('http://api.tbotechnology.in/TBOHolidays_HotelAPI/CityList', [
    //               'CountryCode' => $country->iso2,
    //           ]);

    //         if ($response->successful() && $response->json('Status.Code') === 200) {
    //             $cityList = $response->json('CityList') ?? [];

    //             foreach ($cityList as $tboCity) {
    //                 $cityName = $tboCity['Name'] ?? null;
    //                 $tboCode = $tboCity['Code'] ?? null;

    //                 if ($cityName && $tboCode) {
    //                     $normalizedCityName = strtolower(preg_replace('/\s+|,/', '', $cityName));
                        
    //                     $city = City::get()->filter(function ($item) use ($normalizedCityName, $country) {
    //                         $dbNormalized = strtolower(preg_replace('/[\s,]+/', '', $item->name));
    //                         return $dbNormalized === $normalizedCityName && $item->country_code === $country->iso2;
    //                     })->first();

    //                     if ($city) {
    //                         $city->tbo_code = $tboCode;
    //                         $city->save();
                            
    //                         $this->command->info("✅ Updated: {$city->name} → TBO Code: {$tboCode}\n");
    //                     } else {
                           
    //                         $city = City::create([
    //                             'name' => $cityName,
    //                             'country_code' => $country->iso2,
    //                             "tbo_code" => $tboCode,
    //                             'state_id' => 001,
    //                             'state_code' => 'DEFAULT',
    //                             'country_id' => $country->id,
    //                             'latitude' => 0.000000,
    //                             'longitude' => 0.000000,
    //                         ]);
    //                         $this->command->info("New City Created: {$cityName} in {$country->iso2}\n");
        
    //                     }
    //                 }
    //             }
    //         } else {
    //             $this->command->info("❌ Failed for {$country->iso2}: {$response->status()} - {$response->body()}\n");
    //         }

    //         sleep(1); 

    //     }
    //     DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    //     $this->command->info('Rezlive city codes updated!');
    // }


    public function run()
    {
        $username = config('services.tbo.username');
        $password = config('services.tbo.password');
        $this->command->info("Starting TBO City Code Seeder...");

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        $allCities = City::select('id', 'name', 'country_code')->get();
        $cityMap = $allCities->mapWithKeys(function ($city) {
            $normalized = strtolower(preg_replace('/[\s,]+/', '', trim(explode(',',$city->name)[0])));
            return [$city->country_code . '|' . $normalized => $city];
        });

        foreach (Country::select('id','iso2')->get() as $country) {

            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->withBasicAuth($username, $password)
            ->post('http://api.tbotechnology.in/TBOHolidays_HotelAPI/CityList', [
                'CountryCode' => $country->iso2,
            ]);

            if ($response->successful() && $response->json('Status.Code') === 200) {
                $cityList = $response->json('CityList') ?? [];
                $newCities = [];

                foreach ($cityList as $tboCity) {
                    $cityName = $tboCity['Name'] ?? null;
                    $tboCode = $tboCity['Code'] ?? null;

                    if ($cityName && $tboCode) {
                        $key = $country->iso2 . '|' . strtolower(preg_replace('/[\s,]+/', '', trim(explode(',',$cityName)[0])));
                        $city = $cityMap[$key] ?? null;

                        if ($city) {
                            $city->tbo_code = $tboCode;
                            $city->save();
                            $this->command->info("✅ Updated: {$city->name} → TBO Code: {$tboCode}");
                        } else {
                            $newCities[] = [
                                'name' => $cityName,
                                'country_code' => $country->iso2,
                                'tbo_code' => $tboCode,
                                'state_id' => 1,
                                'state_code' => 'DEFAULT',
                                'country_id' => $country->id,
                                'latitude' => 0.0,
                                'longitude' => 0.0,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                            $this->command->info("New City Queued: {$cityName} in {$country->iso2}");
                        }
                    }
                }

                if (!empty($newCities)) {
                    City::insert($newCities);
                    $this->command->info("🚀 Inserted " . count($newCities) . " new cities for {$country->iso2}");
                }
            } else {
                $this->command->info("❌ Failed for {$country->iso2}: {$response->status()} - {$response->body()}");
            }

            // Optional delay if API requires
            usleep(250000);
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        $this->command->info('✅ TBO city codes seeding complete!');
    }

}
