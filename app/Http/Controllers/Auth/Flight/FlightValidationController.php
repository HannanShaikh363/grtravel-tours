<?php

namespace App\Http\Controllers\Auth\Flight;

use App\Http\Controllers\Controller;
use App\Services\AmadeusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FlightValidationController extends Controller
{


    public function validateFlight(Request $request)
    {

        $validatedData = $request->validate([
            // 'flight_number' => ['required', 'regex:/^[A-Z]{2}-\d{3}$/'], // Matches PK-671
            'flight_date' => ['required', 'date', 'after_or_equal:today'],
        ]);
        $flightData = ['flight_number' => $request->input('flight_number'), 'flight_date' => $request->input('flight_date')];
        return $this->scheduleFlight($flightData);
    }


    public function scheduleFlight($flightData)
    {


        // Validate flight number format
        if (preg_match('/^([A-Za-z]+)-?(\d+)$/', $flightData['flight_number'], $matches)) {
            $airlineCode = strtoupper($matches[1]);  // Airline code (e.g., "OD")
            $flightNumber = $matches[2];  // Flight number (e.g., "136")
        } else {
            // Log the error and return a response
            Log::error('Invalid flight number format.', ['flight_number' => $flightData['flight_number']]);
            return response()->json(['error' => 'Invalid flight number format. Expected format: CODE-NUMBER or CODENUMBER'], 400);
        }

        // API URL
        $apiUrl = 'https://test.api.amadeus.com/v2/schedule/flights';

        // Initialize the Amadeus service to fetch the access token
        $amadeus = new AmadeusService();

        try {
            // Fetch flight details from Amadeus API
            if ($flightNumber && $airlineCode) {
                $flight_params = [
                    'carrierCode' => $airlineCode,  // Corrected key
                    'flightNumber' => $flightNumber,  // Corrected key
                    'scheduledDepartureDate' => $flightData['flight_date'],  // Ensure this is in 'YYYY-MM-DD' format
                ];
                
                // dd($flight_params);
                // Send GET request with correct headers and parameters
                $response = Http::withHeaders([
                    'Authorization' => "Bearer " . $amadeus->getAccessToken(),  // Using access token
                ])->get($apiUrl, $flight_params);  // Sending query parameters as an associative array

                // Check if the response was successful
                // dd($response);
                if ($response->successful()) {
                    return response()->json($response->json(), 200);  // Return the flight details
                } else {
                    // Log the error and return a response
                    Log::error('Failed to fetch flight details from Amadeus API.', [
                        'status_code' => $response->status(),
                        'response' => $response->body(),
                        'flight_params' => $flight_params,
                    ]);
                    return response()->json(['error' => 'Flight details not found or API error.'], $response->status());
                }
            } else {
                // Log the error and return a response
                Log::error('Missing flight number or airline code.', ['flight_data' => $flightData]);
                return response()->json(['error' => 'Missing flight number or airline code.'], 400);
            }
        } catch (\Exception $e) {
            // Log the exception error and return a response
            Log::error('An error occurred while fetching flight details.', [
                'error_message' => $e->getMessage(),
                'stack_trace' => $e->getTraceAsString(),
                'flight_data' => $flightData,
            ]);
            return response()->json(['error' => 'An error occurred while fetching flight details: ' . $e->getMessage()], 500);
        }
    }
}
