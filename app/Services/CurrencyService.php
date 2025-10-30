<?php

namespace App\Services;

use GuzzleHttp\Client;
use App\Models\CurrencyRate;

class CurrencyService
{
    protected $client;
    protected $apiUrl;
    protected $apiKey;

    public function __construct()
    {
        $this->client = new Client();
        $this->apiUrl = 'https://v6.exchangerate-api.com/v6'; // Replace with your API endpoint
        $this->apiKey = env('CURRENCY_API_KEY');
    }

    public function fetchDailyRates($baseCurrency = 'USD')
    {
        $url = "{$this->apiUrl}/{$this->apiKey}/latest/{$baseCurrency}";
        $response = $this->client->get($url);

        $data = json_decode($response->getBody(), true);

        if (isset($data['conversion_rates'])) {
            foreach ($data['conversion_rates'] as $targetCurrency => $rate) {

                if(!in_array($targetCurrency, config('constants.currency'))) {
                    continue;
                }
                CurrencyRate::updateOrCreate(
                    [
                        'base_currency' => $baseCurrency,
                        'target_currency' => $targetCurrency,
                        'rate_date' => now()->format('Y-m-d')
                    ],
                    [
                        'rate' => $rate
                    ]
                );
            }
        }

    }

    public static function convertCurrencyToUsd($to, $amount)
    {
        if($to == 'USD') {
            return $amount;
        }
        $currency = CurrencyRate::where('target_currency',$to)->orderby('rate_date','desc')->first();
        if ($currency) {
            $cleanAmount = floatval(str_replace(',', '', $amount));
            $rate = floatval(str_replace(',', '', $currency->rate));
            $convertedAmount = $cleanAmount / $rate;
            return $convertedAmount;
        }
        return false;
    }

    public static function convertCurrencyFromUsd($to, $amount)
    {
        if($to == 'USD') {
            return $amount;
        }
        $currency = CurrencyRate::where('target_currency',$to)->orderby('rate_date','desc')->first();
        if ($currency) {
            $cleanAmount = floatval(str_replace(',', '', $amount));
            $rate = floatval(str_replace(',', '', $currency->rate));
            $convertedAmount = $cleanAmount * $rate;
            return $convertedAmount;
        }
        return false;
    }


    public static function convertToMYR($amount, $currency)
    {
        if ($currency == 'MYR') {
            return $amount; // No conversion needed if the currency is already MYR
        }
    
        // Fetch the latest conversion rate from the target currency to MYR
        $currencyRate = CurrencyRate::where('target_currency', 'MYR')
            ->where('base_currency', $currency) // Assuming you have both base and target currency in your table
            ->orderby('rate_date', 'desc') // Get the latest rate based on the date
            ->first();
            
            // If the rate exists, convert the amount to MYR
            if ($currencyRate) {
                $convertedAmount = $amount * $currencyRate->rate;
                return $convertedAmount;
            }
    
        // If no conversion rate is found, return false or handle error as needed
        return false;
    }


    public static function getCurrencyRate($currency)
    {
    
        $currencyRate = CurrencyRate::where('target_currency', $currency)
            // ->where('base_currency', 'MYR') // Assuming you have both base and target currency in your table
            ->orderby('rate_date', 'desc') // Get the latest rate based on the date
            ->first();
            
            if ($currencyRate) {
                
                return $currencyRate;
            }
    
        return false;
    }
    

}
