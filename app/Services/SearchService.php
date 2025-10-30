<?php
namespace App\Services;

use App\Models\Location;
use App\Models\Country;
use App\Models\City;
use App\Models\Hotel;
use App\Models\ContractualHotel;
class SearchService
{

    public function searchLocations(?string $query): array
    {
        $query = trim($query);

        $locations = Location::with(['country:id,name', 'city:id,name'])
            ->where(function ($q) use ($query) {
                $q->where('name', '=', $query)
                  ->orWhere('name', 'LIKE', "%{$query}%")
                  ->orWhereHas('city', fn($q) => $q->where('name', '=', $query)->orWhere('name', 'LIKE', "%{$query}%"))
                  ->orWhereHas('country', fn($q) => $q->where('name', '=', $query)->orWhere('name', 'LIKE', "%{$query}%"));
            })
            ->orderByRaw('CASE WHEN name = ? THEN 0 ELSE 1 END', [$query])
            ->orderByRaw('LENGTH(name)')
            ->orderBy('name')
            ->limit(10)
            ->get();

        return $locations->map(function ($location) {
            return [
                'id'        => $location->id,
                'name'      => $location->name,
                'country'   => optional($location->country)->name,
                'city'      => optional($location->city)->name,
                'latitude'  => $location->latitude,
                'longitude' => $location->longitude,
            ];
        })->toArray();
    }

    public function getCountries()
    {
        $countries = Country::select('id', 'name', 'code')->orderBy('name')->get();
        return response()->json($countries);
    }

    public function getCitiesByCountry($country_id)
    {
        $cities = City::where('country_id', $country_id)
                      ->select('id', 'name')
                      ->orderBy('name')
                      ->get();

        return response()->json($cities);
    }


    public function searchCities(?string $query){
        $cities = City::with('country')
        ->where('name', 'LIKE', "%{$query}%")
        ->whereNotNull('rezlive_code')
        ->orWhereHas('country', function ($q) use ($query) {
            $q->where('name', 'LIKE', "%{$query}%");
        })
        ->limit(15)
        ->get();

        $results = $cities->map(function ($city) {
            return [
                'id'   => $city->rezlive_code,
                'name' => "{$city->name}, {$city->country?->name}",
                'country_code' => "{$city->country?->iso2}",
            ];
        });
        return $results;
    }

    public function searchCountries(?string $query){

        $countries = Country::where('name', 'LIKE', "%{$query}%")
        ->limit(15)
        ->get();

        $results = $countries->map(function ($country) {
            return [
                'country_code'   => $country->iso2,
                'name' => "{$country->name}, $country->iso2",
            ];
        });
        return $results;
    }

    public function searchCityAndHotel(?string $keyword)
    {

        // Search cities
        $cities = City::query()
            ->where(function ($q) {
                $q->whereNotNull('rezlive_code')
                ->orWhereNotNull('tbo_code');
            })
            ->when($keyword, function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%");
            })
            ->get()
            ->map(function ($city) {
                return [
                    'id' => (string) $city->id,
                    'label' => "{$city->name}, {$city->country_code}",
                    'type' => 'city',
                    'rezlive_code' => $city->rezlive_code,
                    'tbo_code' => $city->tbo_code,
                    'country_code' => $city->country_code,
                ];
            });

        // Search hotels
        $hotels = Hotel::query()
        ->where(function ($q) {
            $q->whereNotNull('rezlive_hotel_code')
              ->orWhereNotNull('tbo_hotel_code');
        })
        ->when($keyword, fn($q) => $q->where('hotel_name', 'like', "%{$keyword}%"))
        ->with('city:id,name,country_code')
        ->has('city')              // exclude hotels with no city
        ->limit(10)
        ->get()
        ->map(function ($hotel) {
            // these are guaranteed non-null because of ->has('city')
            $city = $hotel->city;
            return [
                'id'     => (string) $hotel->id,
                'label'        => "{$hotel->hotel_name}, {$city->name}, {$city->country_code}",
                'type'         => 'hotel',
                'rezlive_code' => $hotel->rezlive_hotel_code,
                'tbo_code'     => $hotel->tbo_hotel_code,
                'country_code' => $city->country_code,
            ];
        });

        // Merge and return
        return $cities->merge($hotels)->values();
    }
    public function searchContractulCityAndHotel(?string $keyword)
{
    // Search cities directly from City model
    $cities = City::query()
        ->when($keyword, fn($q) => $q->where('name', 'like', "%{$keyword}%"))
        ->with('country:id,name') // eager-load country for labels
        ->get()
        ->map(fn($city) => [
            'id'           => (string) $city->id,
            'label'        => "{$city->name}, " . optional($city->country)->name,
            'type'         => 'city',
            'name' =>"{$city->name}, " . optional($city->country)->name,
            'country_code' => optional($city->country)->name,
        ])
        ->values();

    // Search hotels
    $hotels = ContractualHotel::query()
        ->when($keyword, fn($q) =>
            $q->where('hotel_name', 'like', "%{$keyword}%")
              ->orWhereHas('cityRelation', fn($q2) =>
                  $q2->where('name', 'like', "%{$keyword}%")
              )
        )
        ->with(['cityRelation:id,name', 'countryRelation:id,name'])
        ->limit(10)
        ->get()
        ->map(function ($hotel) {
            $city    = optional($hotel->cityRelation);
            $country = optional($hotel->countryRelation);

            return [
                'id'      => (string) $hotel->id,
                'label'   => "{$hotel->hotel_name}, {$city->name}, {$country->name}",
                'name' =>"{$hotel->hotel_name}",
                'type'    => 'hotel',
                'country' => $country->name,
            ];
        })
        ->values();
        // echo "<pre>";print_r($hotels);die();

    return $cities->merge($hotels)->values();
}




}