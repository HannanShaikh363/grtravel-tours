<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AmadeusService
{
    protected $clientId;

    protected $clientSecret;

    protected $tokenUrl;

    protected $cacheKey = 'amadeus_access_token';

    public function __construct()
    {
        $this->clientId = config('services.amadeus.client_id');
        $this->clientSecret = config('services.amadeus.client_secret');
        $this->tokenUrl = 'https://test.api.amadeus.com/v1/security/oauth2/token';
    }

    public function getAccessToken()
    {
        // Check if the token is already cached
        if (Cache::has($this->cacheKey)) {
            return Cache::get($this->cacheKey);
        }
        // Otherwise, fetch a new token
        return $this->fetchAccessToken();
    }

    protected function fetchAccessToken()
    {
        Log::info('Fetching access token from Amadeus API...');

        $response = Http::asForm()->post($this->tokenUrl, [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        Log::info('Response from Amadeus:', ['status' => $response->status(), 'body' => $response->body()]);

        if ($response->successful()) {
            $data = $response->json();
            Log::info('Access token fetched successfully.', ['token' => $data['access_token']]);

            $expiresIn = $data['expires_in'];
            $token = $data['access_token'];

            // Cache the token
            Cache::put($this->cacheKey, $token, $expiresIn - 60);
            return $token;
        }

        Log::error('Failed to fetch access token.', ['status' => $response->status(), 'body' => $response->body()]);
        return false;
    }
}
