<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use App\Models\City;
use App\Models\Country;
use Illuminate\Support\Facades\DB;


class RezliveCityCodesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        $rezPath = database_path('cities-rezlive.csv');
        if (File::exists($rezPath)) {
            $rows = array_map('str_getcsv', file($rezPath));
            $header = array_map('strtolower', array_shift($rows)); // Skip and store header
            $countryCodeMap = [
                'UK' => 'GB',
                // Add more if needed
            ];
            foreach ($rows as $row) {
                $data = array_combine($header, $row);
    
                $csvName = trim($data['name']);
                $cityCode = trim($data['city_code']);
                $countryCode = trim($data['country_code']);
                $csvCountryCode = $countryCode;
                $mappedCountryCode = $countryCodeMap[$csvCountryCode] ?? $csvCountryCode;
                // Try exact match
                $city = City::where('name', $csvName)
                    ->where('country_code', $mappedCountryCode) // Add this
                    ->first();

                // Fuzzy match if city has comma
                if (!$city && str_contains($csvName, ',')) {
                    $shortName = trim(explode(',', $csvName)[0]);

                    $city = City::where('name', $shortName)
                                ->where('country_code', $mappedCountryCode) // Add this
                                ->first();
                }
                    
                // If city still not found, create it
                if (!$city) {
                    $country = Country::where('iso2', $mappedCountryCode)->first();
                    $city = City::create([
                        'name' => $csvName,
                        'country_code' => $mappedCountryCode,
                        "rezlive_code" => $cityCode,
                        'state_id' => 001,
                        'state_code' => 'DEFAULT',
                        'country_id' => $country->id,
                        'latitude' => 0.000000,
                        'longitude' => 0.000000,
                    ]);
                    $this->command->info("Created new city: {$csvName}");
                } else {
                    // If city found, update the third-party code
                    $city->update([
                        "rezlive_code" => $cityCode,
                    ]);
                    $this->command->info("Updated rezlive_code for city: {$city->name}");
                }
            }
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            $this->command->info('Rezlive city codes updated!');
            // $this->command->info('Rezlive codes updated!');
        }
    }
}
