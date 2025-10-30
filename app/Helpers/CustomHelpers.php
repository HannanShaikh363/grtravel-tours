<?php
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use App\Models\City;
use App\Models\Country;
use App\Models\Configuration;
use Illuminate\Support\Facades\Storage;



if (! function_exists('convertToUserTimeZone')) {
    function convertToUserTimeZone($dateTime, $format = 'Y-m-d H:i:s')
    {
        // Ensure we have a valid timezone
        $timeZone = session('user_timezone')
            ?: session('timezone')
            ?: config('app.timezone', 'UTC');

        // Parse the given date/time and convert it to the user's time zone
        return Carbon::parse($dateTime)
            ->timezone($timeZone)
            ->format($format);
    }
}

if(! function_exists('getCityAndCountry')){
    function getCityAndCountry($location)
    {
        // Check if 'country_id' is present in the location data
        if (isset($location['country_id'])) {
            // Find the country by ID
            $country = Country::find($location['country_id']);
        } else {
            // Fallback to finding the country by name
            $country = Country::where('name', $location['country'])->first();
        }

        if ($country) {
            // Check if 'city_id' is present in the location data
            if (isset($location['city_id'])) {
                // Find the city by ID
                $city = City::find($location['city_id']);
            } else {
                // Fallback to finding the city by name and country_id
                $city = City::where('country_id', $country->id)
                    ->where('name', $location['city'])
                    ->first();
            }

            // If both country and city exist, return their IDs
            if ($city) {
                return [$country->id, $city->id];
            }
        }

        // If no match found, return [null, null] as a default value
        return [null, null];
    }
}

if (!function_exists('getTimezoneFromCountryCode')) {
    function getTimezoneFromCountryCode(string $countryCode = 'MY'): array
    {
        $timezones = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $countryCode);
        $manualAbbreviations = config('timezones.abbreviations');

        $results = [];

        foreach ($timezones as $tz) {
            $dt = new DateTime('now', new DateTimeZone($tz));
            $abbreviation = $manualAbbreviations[$tz] ?? $dt->format('T');
            $results[] = [
                'timezone' => $tz,
                'abbreviation' => $abbreviation,
                'utc_offset' => $dt->format('P'),
            ];
        }

        return $results;
    }
}


if(!function_exists('getTimezoneAbbreviationFromCountryCode')){

    function getTimezoneAbbreviationFromCountryCode(string $countryCode = 'MY'): ?string
    {
        $timezones = \DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $countryCode);

        if (empty($timezones)) {
            return null;
        }

        $tz = $timezones[0]; // Take the first timezone
        $manualAbbreviations = config('timezones.abbreviations');
        
        $dt = new \DateTime('now', new DateTimeZone($tz));
        return $manualAbbreviations[$tz] ?? $dt->format('T');
    }
}

if(!function_exists('calculateTaxedPrice')){
    function calculateTaxedPrice(float $subtotal, string $paymentMethod = 'razerpay'): array
    {
        $taxPercent = Configuration::getValue($paymentMethod, 'tax', 0);
        $taxAmount = round($subtotal * ($taxPercent / 100),2);
        $total = round(($subtotal + $taxAmount),2);

        return [
            'subtotal' => $subtotal,
            'tax_percent' => $taxPercent,
            'tax_amount' => $taxAmount,
            'total_amount' => $total,
        ];
    }
}

if(!function_exists('logXml')){
    function logXml($agentCode, $type, $content)
    {
        // dd($content, $type);
        $date = Carbon::now()->format('Y-m-d');
        $timestamp = Carbon::now()->timestamp;

        $folder = "rezlive_logs/{$date}/{$agentCode}";
        // $folder = "rezlive_logs/Case_{$caseNumber}";
        $filename = "{$type}-{$timestamp}.xml";

        if ($content instanceof SimpleXMLElement) {
            $content = $content->asXML(); // This converts the object to actual XML string
        }
        Storage::disk('local')->put("{$folder}/{$filename}", $content);
    }
}

if (!function_exists('isStaffLinkedToAdmin')) {
    function isStaffLinkedToAdmin($user)
    {
        return $user->type === 'staff' && User::where('type', 'admin')
            ->where('agent_code', $user->agent_code)
            ->exists();
    }
}