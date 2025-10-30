<?php

namespace App\Services;

use App\Http\Controllers\SurchargeController;
use App\Mail\BookingMail;
use App\Mail\BookingMailToAdmin;
use App\Mail\TransferBookingInvoiceMail;
use App\Mail\TransferBookingVoucherMail;
use App\Models\AgentPricingAdjustment;
use App\Models\Booking;
use App\Models\CancellationPolicies;
use App\Models\City;
use App\Models\Company;
use App\Models\Country;
use App\Models\FleetBooking;
use App\Models\Location;
use App\Models\MeetingPoint;
use App\Models\Rate;
use App\Models\Surcharge;
use App\Models\TransferHotel;
use App\Models\User;
use App\Models\VoucherRedemption;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use ProtoneMedia\Splade\Facades\Toast;
use Carbon\Carbon;
use App\Jobs\CreateBookingPDFJob;
use App\Jobs\SendEmailJob;


class BookingService
{

    public function getBookingData(Request $request)
    {
        // Validate the input fields
        $validated = $request->validate([
            'pickup_address' => 'nullable',
            'travel_location' => 'nullable',
            'dropoff_location' => 'nullable',
            'travel_date' => 'nullable|date',
            'travel_time' => 'nullable|date_format:H:i',
            'vehicle_seating_capacity' => 'nullable|integer|min:1',
            'vehicle_luggage_capacity' => 'nullable|integer|min:1',
            'currency' => 'nullable',
        ]);

        $showRates = collect();
        $toLocationIdListRate = collect();

        $dropoffAddress = $request->input('dropoff_address'); // Get the dropoff address


        $pick_date = $request->input('travel_date');
        $pick_time = $request->input('travel_time') ?? "00:00";
        $currency = $request->has('currency') ? $request->input('currency') : null;
        //From Travel location


        //Dropoff location
        $toLocationId = null;
        $fromLocationId = null;
        // $dropLocationName = $request->has('dropoff_location') && is_array($request->input('dropoff_location')) ? $request->input('dropoff_location')['name'] : null;

        // if (!is_null($dropLocationName)) {
        //     $toLocationId = Location::where('name', $dropLocationName)->value('id');
        // }
        if (is_array($request->input('travel_location'))) {
            $travelLocationName = $request->input('travel_location')['name'];
            $fromLocationId = Location::where('name', $travelLocationName)->first();
            if ($fromLocationId) {
                $countryId = $fromLocationId->country_id;
                $fromLocationId = $fromLocationId->id;
                if (!$request->input('advanceOpen')) {
                    if (!is_null($toLocationId))
                        $showRates = Rate::where('from_location_id', $fromLocationId)->where('to_location_id', $toLocationId);
                    else
                        $showRates = Rate::where('from_location_id', $fromLocationId);
                } else {


                    if ($request->has('dropoff_address') && in_array($request->input('dropoff_type'), ['airport', 'locality'])) {

                        $toLocationName = isset($request->input('dropoff_address')['name']) ? $request->input('dropoff_address')['name'] : null;
                        $toLocationId = Location::where('name', $toLocationName)->value('id');
                        $showRates = Rate::where('to_location_id', $toLocationId);
                        if ($request->input('pickup_type') == 'airport') {
                            $fromLocationName = isset($request->input('pickup_address')['name']) ? $request->input('pickup_address')['name'] : null;
                            $fromLocationId = Location::where('name', $fromLocationName)->value('id');
                            $showRates->where('from_location_id', $fromLocationId);
                        } else {

                            $showRates->where('from_location_id', $fromLocationId);
                        }
                    } else {

                        if (!is_null($toLocationId))
                            $showRates = Rate::where('to_location_id', $toLocationId)->where('from_location_id', $fromLocationId);
                        else
                            $showRates = Rate::where('from_location_id', $fromLocationId);
                    }


                    if ($request->has('vehicle_seating_capacity') && $request->input('vehicle_seating_capacity') > 0) {
                        $showRates->where('vehicle_seating_capacity', '>=', $request->input('vehicle_seating_capacity'));
                    }

                    if ($request->has('vehicle_luggage_capacity') && $request->input('vehicle_luggage_capacity') > 0) {
                        $showRates->where('vehicle_luggage_capacity', '>=', $request->input('vehicle_luggage_capacity'));
                    }
                }

                // Retrieve the rates
                if ($request->has('price') && $request->input('price') > 0) {
                    $showRates->where('rate', '>=', $request->input('price'))->orderBy('rate', 'ASC');
                }
                if ($request->has('destination') && !empty($request->input('destination'))) {
                    $showRates->where('to_location_id', $request->input('destination'));
                }
                if ($request->has('hour') && !empty($request->input('hour'))) {
                    $showRates->where('hours', $request->input('hour'))->orderBy('hours', 'asc');
                }
                if ($request->has('package') && !empty($request->input('package'))) {
                    $showRates->where('package', $request->input('package'));
                }
                $toLocationIdListRate = clone $showRates;
                $toLocationIdListRate = $toLocationIdListRate->select('to_location_id')->with('toLocation')->get();

                $showRates = $showRates->with('toLocation', 'fromLocation', 'transport')->paginate(20);
                $showRates->appends($request->query());
                $agentCode = auth()->user()->agent_code;

                $agentId = User::where('agent_code', $agentCode)
                    ->where('type', 'agent')
                    ->value('id');

                $adjustmentRate = AgentPricingAdjustment::where('agent_id', $agentId)->where('transaction_type', 'transfer')->where('active', 1)->first();

                // Assuming travel_time is already in HH:MM format
                $travelTimeFormatted = $pick_time; // Keep it in HH:MM format

                // Convert the HH:MM formatted time to Carbon instances for comparison
                $travelTime = Carbon::createFromFormat('H:i', $travelTimeFormatted)->format('H:i:s');
                // Fetch surcharge based on country_id

                // $surcharge = Surcharge::where('country_id', $countryId)->whereRaw("'" . $travelTime . "'" . " BETWEEN start_time and end_time")->first();

                $surcharge = Surcharge::where('country_id', $countryId)
                    ->where(function ($query) use ($travelTime) {
                        $query->where(function ($subQuery) use ($travelTime) {
                            // Case 1: start_time <= end_time (same-day range)
                            $subQuery->whereRaw("'" . $travelTime . "' BETWEEN start_time AND end_time");
                        })
                            ->orWhere(function ($subQuery) use ($travelTime) {
                                // Case 2: start_time > end_time (cross-midnight range)
                                $subQuery->whereRaw("(start_time > end_time AND ('" . $travelTime . "' >= start_time OR '" . $travelTime . "' <= end_time))");
                            });
                    })->first();

                // Apply surcharges to rates
                foreach ($showRates as $rate) {
                    $countryId = $rate->fromLocation->country_id;
                    if (!is_null($currency)) {
                        $usd_rate = CurrencyService::convertCurrencyToUsd($rate->currency, $rate->rate);
                        $rate->rate = round(CurrencyService::convertCurrencyFromUsd($currency, $usd_rate), 2);
                        $rate->currency = $currency;
                    }

                    if ($surcharge) {
                        $rate->surcharge_percentage = $surcharge->surcharge_percentage;
                        $rate->rate = $rate->rate + ($rate->rate * ($surcharge->surcharge_percentage / 100));
                    } else {
                        $rate->surcharge_percentage = 0;
                    }
                    if (!is_null($adjustmentRate)) {
                        if ($adjustmentRate->percentage_type && $adjustmentRate->percentage_type === 'surcharge') {

                            $rate->rate = round($rate->rate + ($rate->rate * ($adjustmentRate->percentage / 100)), 2);
                        } else {
                            $rate->rate = round($rate->rate - ($rate->rate * ($adjustmentRate->percentage / 100)), 2);
                        }
                    }
                }
            }
        }
        // dd($showRates);
        if ($pick_time && $pick_date) {

            // Set the target date and time
            $targetDate = Carbon::createFromFormat('Y-m-d H:i:s', $pick_date . ' ' . $pick_time . ':00'); // Replace with your target date and time
            // Get the current date and time
            $currentDate = Carbon::now();
            // Calculate the difference in days
            $remainingDays = $currentDate->diffInDays($targetDate, false); // 'false' to allow negative values if targetDate is in the past
        }
        $remainingDays = $remainingDays ?? 0;


        return [
            'showRates' => $showRates,
            'dropoffAddress' => $dropoffAddress,
            'pick_date' => $pick_date,
            'pick_time' => $pick_time,
            'fromLocationId' => $fromLocationId,
            'remainingDays' => $remainingDays,
            'cancellationPolicy' => CancellationPolicies::where('active', 1)->where('type', 'transfer')->first(),
            'toLocationId' => $toLocationId,
            'toLocationValues' => $toLocationIdListRate->pluck('toLocation')->unique(),
            'booking_date' => $pick_date, // Assuming the booking date is derived from $pick_date
            'currency' => $currency,
        ];
    }

    public function getFlightDetails(Request $request)
    {

        // Check if depart flight number, return depart flight number, or return arrival flight number is provided
        $departFlightNumberWithCode = $request->input('depart_flight_number');
        $returnDepartFlightNumberWithCode = $request->input('return_depart_flight_number');
        $returnArrivalFlightNumberWithCode = $request->input('return_arrival_flight_number');

        // Initialize variables for airline codes and flight numbers
        $depart_airlineCode = null;
        $depart_flightNumber = null;
        $return_depart_airlineCode = null;
        $return_depart_flightNumber = null;
        $return_arrival_airlineCode = null;
        $return_arrival_flightNumber = null;

        // Validate depart flight number
        if ($departFlightNumberWithCode) {
            if (preg_match('/^([A-Za-z]+)-?(\d+)$/', $departFlightNumberWithCode, $matches)) {
                $depart_airlineCode = strtoupper($matches[1]);
                $depart_flightNumber = $matches[2];
            } else {
                return response()->json(['error' => 'Invalid depart flight number format. Expected format: CODE-NUMBER or CODENUMBER'], 400);
            }
        }

        // Validate return depart flight number
        if ($returnDepartFlightNumberWithCode) {
            if (preg_match('/^([A-Za-z]+)-?(\d+)$/', $returnDepartFlightNumberWithCode, $matches)) {
                $return_depart_airlineCode = strtoupper($matches[1]);
                $return_depart_flightNumber = $matches[2];
            } else {
                return response()->json(['error' => 'Invalid return depart flight number format. Expected format: CODE-NUMBER or CODENUMBER'], 400);
            }
        }

        // Validate return arrival flight number
        if ($returnArrivalFlightNumberWithCode) {
            if (preg_match('/^([A-Za-z]+)-?(\d+)$/', $returnArrivalFlightNumberWithCode, $matches)) {
                $return_arrival_airlineCode = strtoupper($matches[1]);
                $return_arrival_flightNumber = $matches[2];
            } else {
                return response()->json(['error' => 'Invalid return arrival flight number format. Expected format: CODE-NUMBER or CODENUMBER'], 400);
            }
        }

        // Check if arrival flight number is provided
        $arrivalFlightNumberWithCode = $request->input('arrival_flight_number');
        $arrival_airlineCode = null;
        $arrival_flightNumber = null;

        // Validate arrival flight number
        if ($arrivalFlightNumberWithCode) {
            if (preg_match('/^([A-Za-z]+)-?(\d+)$/', $arrivalFlightNumberWithCode, $matches)) {
                $arrival_airlineCode = strtoupper($matches[1]);
                $arrival_flightNumber = $matches[2];
            } else {
                return response()->json(['error' => 'Invalid arrival flight number format. Expected format: CODE-NUMBER or CODENUMBER'], 400);
            }
        }

        // API URL and key for fetching flight details
        $apiUrl = 'https://test.api.amadeus.com/v2/schedule/flights';
        $amadeus = new AmadeusService();
        $depart_flight_data = null;
        $arrival_flight_data = null;
        $return_depart_flight_data = null;
        $return_arrival_flight_data = null;

        try {
            // Fetch departure flight details if provided
            if ($depart_flightNumber && $depart_airlineCode) {
                $depart_flight_params = [
                    'carrierCode' => $depart_airlineCode,
                    'flightNumber' => $depart_flightNumber,
                    'scheduledDepartureDate' => $request->input('depart_flight_date'),
                ];
                $depart_response = Http::withHeaders([
                    'Authorization' => "Bearer " . $amadeus->getAccessToken(),
                ])->get($apiUrl, $depart_flight_params);

                if ($depart_response->successful()) {
                    $depart_flight_data = $depart_response->json()['data'] ?? null;
                }
            }

            // Fetch return departure flight details if provided
            if ($return_depart_flightNumber && $return_depart_airlineCode) {
                $return_depart_flight_params = [
                    'carrierCode' => $return_depart_airlineCode,
                    'flightNumber' => $return_depart_flightNumber,
                    'scheduledDepartureDate' => $request->input('return_depart_flight_date'),
                ];
                $return_depart_response = Http::withHeaders([
                    'Authorization' => "Bearer " . $amadeus->getAccessToken(),
                ])->get($apiUrl, $return_depart_flight_params);

                if ($return_depart_response->successful()) {
                    $return_depart_flight_data = $return_depart_response->json()['data'] ?? null;
                }
            }

            // Fetch return arrival flight details if provided
            if ($return_arrival_flightNumber && $return_arrival_airlineCode) {
                $return_arrival_flight_params = [
                    'carrierCode' => $return_arrival_airlineCode,
                    'flightNumber' => $return_arrival_flightNumber,
                    'scheduledDepartureDate' => $request->input('return_arrival_flight_date'),
                ];
                $return_arrival_response = Http::withHeaders([
                    'Authorization' => "Bearer " . $amadeus->getAccessToken(),
                ])->get($apiUrl, $return_arrival_flight_params);

                if ($return_arrival_response->successful()) {
                    $return_arrival_flight_data = $return_arrival_response->json()['data'] ?? null;
                }
            }

            // Fetch arrival flight details if provided
            if ($arrival_flightNumber && $arrival_airlineCode) {
                $arrival_flight_params = [
                    'carrierCode' => $arrival_airlineCode,
                    'flightNumber' => $arrival_flightNumber,
                    'scheduledDepartureDate' => $request->input('arrival_flight_date'),
                ];
                $arrival_response = Http::withHeaders([
                    'Authorization' => "Bearer " . $amadeus->getAccessToken(),
                ])->get($apiUrl, $arrival_flight_params);

                if ($arrival_response->successful()) {
                    $arrival_flight_data = $arrival_response->json()['data'] ?? null;
                }
            }
            // Check if any flight data was found
            // if ($depart_flight_data || $return_depart_flight_data || $arrival_flight_data || $return_arrival_flight_data) {
            if (($arrival_flight_data && !$depart_flight_data && !$return_arrival_flight_data && !$return_depart_flight_data) || ($depart_flight_data && !$arrival_flight_data && !$return_arrival_flight_data && !$return_depart_flight_data) || ($depart_flight_data && $arrival_flight_data && !$return_arrival_flight_data && !$return_depart_flight_data) || ($depart_flight_data && $return_arrival_flight_data && !$return_depart_flight_data && !$arrival_flight_data) || ($arrival_flight_data && $return_depart_flight_data && !$return_arrival_flight_data && !$depart_flight_data) || ($depart_flight_data && $return_depart_flight_data && $arrival_flight_data && $return_arrival_flight_data)) {
                return response()->json([
                    'success' => true,
                    'depart_flight_data' => $depart_flight_data,
                    'return_depart_flight_data' => $return_depart_flight_data,
                    'arrival_flight_data' => $arrival_flight_data,
                    'return_arrival_flight_data' => $return_arrival_flight_data,
                ]);
            }

            // If no flight details found
            return response()->json([
                'success' => false,
                'message' => 'No flight details found for the provided information.',
            ], 404);
        } catch (\Exception $e) {
            // Handle exception and return a response
            return response()->json([
                'success' => false,
                'message' => 'An error occurred: ' . $e->getMessage(),
            ], 500);
        }
    }

    // public function calculateTotalPrice($basePrice, $rate, $journeyType, Request $request, $booking_currency = null, $rate_currency = null)
    // {
    //     // surcharges
    //     $location_id = Location::where('id', $request->input('from_location_id'))->value('country_id');
    //     $fromLocationId = Country::where('id', $location_id)->value('id');
    //     if ($fromLocationId) {
    //         // Assuming travel_time is already in HH:MM format
    //         $pick_time = $request->input('pick_time');
    //         $travelTimeFormatted = $pick_time; // Keep it in HH:MM format

    //         // Convert the HH:MM formatted time to Carbon instances for comparison
    //         $travelTime = Carbon::createFromFormat('H:i', $travelTimeFormatted)->format('H:i:s');
    //         $surcharge = Surcharge::where('country_id', $fromLocationId)
    //             ->where(function ($query) use ($travelTime) {
    //                 $query->whereRaw("'" . $travelTime . "' BETWEEN start_time AND '23:59:59'")
    //                     ->orWhereRaw("'" . $travelTime . "' BETWEEN '00:00:00' AND end_time");
    //             })->first();
    //         // $surcharge = Surcharge::where('country_id', $fromLocationId)->whereRaw("'" . $travelTime . "'" . " BETWEEN start_time and end_time")->first();

    //         if ($surcharge) {
    //             $basePrice += $surcharge->surcharge_percentage * $basePrice / 100;
    //         }
    //     }

    //     // Double the price if the rate's route_type is 'one_way' and the booking's journey_type is 'two_way'
    //     if ($rate->route_type === 'one_way' && $journeyType === 'two_way') {

    //         $basePrice1 = CurrencyService::convertCurrencyTOUsd($rate_currency, $basePrice * 2);
    //         $basePrice2 = CurrencyService::convertCurrencyFromUsd($booking_currency, $basePrice1);
    //         return  round($basePrice2, 2);
    //     }

    //     // If conditions are not met, return the base price
    //     $basePrice1 = CurrencyService::convertCurrencyTOUsd($rate_currency, $basePrice);
    //     $basePrice2 = CurrencyService::convertCurrencyFromUsd($booking_currency, $basePrice1);
    //     // dd($basePrice,$basePrice1,$basePrice2);
    //     return round($basePrice2, 2);
    // }

    public function deductCredit(User $user, float $amount, $currency)
    {
        // Only apply credit deduction if the user is an agent
        if ($user->type === 'admin') {
            return true; // No deduction needed for admin
        }
        if ($currency == $user->credit_limit_currency) {
            if ($user->credit_limit < $amount) {
                return false;
            }
        }
        $usercreditLimit = $user->credit_limit;

        if ($currency != $user->credit_limit_currency) {

            if ($amount = CurrencyService::convertCurrencyTOUsd($currency, $amount)) {
                $usercreditLimit = CurrencyService::convertCurrencyToUsd($user->credit_limit_currency, $usercreditLimit);

                if ($usercreditLimit < $amount) {
                    return false;
                }
            }
        }

        if ($currency == $user->credit_limit_currency || $user->credit_limit_currency == 'USD') {
            $deducted = $usercreditLimit - $amount;
        }
        if ($currency != $user->credit_limit_currency && $user->credit_limit_currency != 'USD') {
            $deducted = $usercreditLimit - $amount;
            $deducted = CurrencyService::convertCurrencyFromUsd($user->credit_limit_currency, $deducted);
        }

        // Update the agent's credit limit in the database
        $user->credit_limit = $deducted;
        $user->save();
        return true;
    }

    public function deductCreditWallet(User $user, float $amount, $currency)
    {
        // Only apply credit deduction if the user is an agent
        if ($user->type !== 'agent') {
            return true; // No deduction needed for non-agents
        }

        if ($currency == $user->credit_limit_currency) {
            if ($user->credit_limit < $amount) {
                return false;
            }
        }
        $usercreditLimit = $user->credit_limit;

        if ($currency != $user->credit_limit_currency) {

            if ($amount = CurrencyService::convertCurrencyTOUsd($currency, $amount)) {
                $usercreditLimit = CurrencyService::convertCurrencyToUsd($user->credit_limit_currency, $usercreditLimit);

                if ($usercreditLimit < $amount) {
                    return false;
                }
            }
        }

        if ($currency == $user->credit_limit_currency || $user->credit_limit_currency == 'USD') {
            $deducted = $usercreditLimit - $amount;
        }
        if ($currency != $user->credit_limit_currency && $user->credit_limit_currency != 'USD') {
            $deducted = $usercreditLimit - $amount;
            $deducted = CurrencyService::convertCurrencyFromUsd($user->credit_limit_currency, $deducted);
        }


        // Update the agent's credit limit in the database
        $user->credit_limit = $deducted;
        $user->save();
        return true;
    }

    public function prepareBookingData(Request $request, $fleetBooking, $dropOffName, $pickUpName, $is_updated = 0)
    {
        $user = User::where('id', $fleetBooking->user_id)->first() ?? auth()->user();
        // Retrieve admin and agent logos from the Company table
        $adminLogo = public_path('/img/logo.png');
        // First get the agent_code of the current user
        $agentCode = $user->agent_code;

        $timezone_abbreviation = 'UTC'; // fallback
        $timezones = json_decode($fleetBooking->fromLocation->country->timezones);
        if (is_array($timezones) && isset($timezones[0]->abbreviation)) {
            $timezone_abbreviation = $timezones[0]->abbreviation;
        }

        // Then find the actual agent user who owns this agent_code
        $agent = User::where('type', 'agent')->where('agent_code', $agentCode)->first();

        $agentLogo = null;

        if ($agent) {
            // Now get the logo from the company table using the agent's ID
            $agentLogo = Company::where('user_id', $agent->id)->value('logo');
        }
        $agentLogo = $agentLogo ? public_path(str_replace('/public/', '', $agentLogo)) : $adminLogo;
        if (file_exists($agentLogo) && is_readable($agentLogo)) {
            $imageData = base64_encode(file_get_contents($agentLogo));
        } else {
            // If the agent logo is not found, use the admin logo
            $imageData = base64_encode(file_get_contents($adminLogo));
        }
        // $imageData = base64_encode(file_get_contents($agentLogo));
        $agentLogo = 'data:image/png;base64,' . $imageData;

        $vehicle = $fleetBooking->vehicle_make . ' ' . $fleetBooking->vehicle_model;
        if ($vehicle == 'N-A N-A') {
            $vehicle = '';
        }
        $company = Company::where('user_id', $agent->id ?? $user->id)->first();
        $agentCompanyAddress = $company->address ?? 'Unit B-04-16, Block B, Perdana Selatan, Taman Serdang Perdana Seksyan 1,';
        $agentCompanyName = $company->agent_name ?? 'GR TRAVEL & TOURS SDN BHD';
        $agentCompanyWebsite = $company->agent_website ?? 'booking.grtravels.net';
        $companyCity = $company->city_id ?? '76497';
        $agentCompanyCity = City::where('id', $companyCity)->value('name');
        $agentCompanyZip = $company->zip ?? '43300';
        $companyNumber = $company->agent_number ?? '14 331 9802';
        $companyPhoneCode = $company->phone_code_company ?? '+60';
        $agentCompanyNumber = $companyPhoneCode . ' ' . $companyNumber;

        $currency = $request->input('currency') ?? $fleetBooking->currency;
        $basePrice = $currency . ' ' . $fleetBooking->booking_cost;

        $rateId = $fleetBooking->rate_id;
        $rates = Rate::where('id', $rateId)->first();
        $remarks = $rates->remarks;

        $phone = $agent->phone ?? $user->phone;
        $phoneCode = $agent->phone_code ?? $user->phone_code;
        $hirerEmail = $agent->email ?? $user->email;
        $hirerName = ($agent ?? $user)->first_name . ' ' . ($agent ?? $user)->last_name;

        $booking_status = Booking::where('id', $fleetBooking->booking_id)->first();

        $hirerPhone = $phoneCode . $phone;

        $hasDepartureDate = $fleetBooking->depart_flight_date;
        $hasDepartureTime = $fleetBooking->flight_departure_time;

        if ($hasDepartureDate && $hasDepartureTime) {
            $flight_departure_time = Carbon::parse($hasDepartureDate . ' ' . $hasDepartureTime)->format('F j, Y H:i').' '.$timezone_abbreviation;
        } elseif ($hasDepartureDate) {
            $flight_departure_time = Carbon::parse($hasDepartureDate)->format('F j, Y');
        } elseif ($hasDepartureTime) {
            $flight_departure_time = Carbon::parse($hasDepartureTime)->format('H:i').' '.$timezone_abbreviation;
        } else {
            $flight_departure_time = null;
        }


        $hasDate = $fleetBooking->arrival_flight_date;
        $hasTime = $fleetBooking->flight_arrival_time;

        if ($hasDate && $hasTime) {
            $flight_arrival_time = Carbon::parse($hasDate . ' ' . $hasTime)->format('d M Y, H:i') .' '.$timezone_abbreviation;
        } elseif ($hasDate) {
            $flight_arrival_time = Carbon::parse($hasDate)->format('d M Y');
        } elseif ($hasTime) {
            $flight_arrival_time = Carbon::parse($hasTime)->format('H:i').' '.$timezone_abbreviation;
        } else {
            $flight_arrival_time = null;
        }

        $hasReturnArrivalDate = $fleetBooking->return_arrival_flight_date;
        $hasReturnArrivalTime = $fleetBooking->return_flight_arrival_time;

        if ($hasReturnArrivalDate && $hasReturnArrivalTime) {
            $return_flight_arrival_time = Carbon::parse($hasReturnArrivalDate . ' ' . $hasReturnArrivalTime)->format('F j, Y H:i') .' '.$timezone_abbreviation;
        } elseif ($hasReturnArrivalDate) {
            $return_flight_arrival_time = Carbon::parse($hasReturnArrivalDate)->format('F j, Y');
        } elseif ($hasReturnArrivalTime) {
            $return_flight_arrival_time = Carbon::parse($hasReturnArrivalTime)->format('H:i').' '.$timezone_abbreviation;
        } else {
            $return_flight_arrival_time = null;
        }

        $hasReturnDepartureDate = $fleetBooking->return_depart_flight_date;
        $hasReturnDepartureTime = $fleetBooking->return_flight_departure_time;

        if ($hasReturnDepartureDate && $hasReturnDepartureTime) {
            $return_flight_departure_time = Carbon::parse($hasReturnDepartureDate . ' ' . $hasReturnDepartureTime)->format('F j, Y H:i').' '.$timezone_abbreviation;
        } elseif ($hasReturnDepartureDate) {
            $return_flight_departure_time = Carbon::parse($hasReturnDepartureDate)->format('F j, Y');
        } elseif ($hasReturnDepartureTime) {
            $return_flight_departure_time = Carbon::parse($hasReturnDepartureTime)->format('H:i ').' '.$timezone_abbreviation;
        } else {
            $return_flight_departure_time = null;
        }

        // dd($fleetBooking->return_flight_arrival_time);
        $return_pickup_date = $fleetBooking->return_pickup_date ? Carbon::parse($fleetBooking->return_pickup_date)->format('F j, Y') : 'N/A';
        $return_pickup_time = $fleetBooking->return_pickup_time ? Carbon::parse($fleetBooking->return_pickup_time)->format('H:i').' '.$timezone_abbreviation : 'N/A';
        // $hotelName = $request->input('pickup_address')['name'] ?? $request->input('pickup_address') ?? TransferHotel::where('booking_id', $fleetBooking->id)->value('pickup_hotel_name');
        // $hotelName123 = $request->input('dropoff_address')['name'] ?? $request->input('dropoff_address') ?? TransferHotel::where('booking_id', $fleetBooking->id)->value('dropoff_hotel_name');

        $transferHotels = TransferHotel::where('booking_id', $fleetBooking->id)->get();

        // Extract names from the records
        $pickupHotelName = $transferHotels->first()->pickup_hotel_name ?? 'N/A';
        $returnDropoffHotelName = $transferHotels->first()->return_dropoff_hotel_name ?? 'N/A';

        $dropoffHotelName = $transferHotels->skip(1)->first()->dropoff_hotel_name ?? 'N/A';
        $returnPickupHotelName = $transferHotels->skip(1)->first()->return_pickup_hotel_name ?? 'N/A';

        // Handle cases with only one record
        if ($transferHotels->count() === 1) {
            $dropoffHotelName = $transferHotels->first()->dropoff_hotel_name ?? 'N/A';
            $returnPickupHotelName = $transferHotels->first()->return_pickup_hotel_name ?? 'N/A';
        }

        // Assign to/from locations based on these values
        $toLocation = $dropoffHotelName !== 'N/A'
            ? $dropoffHotelName
            : Location::where('id', $fleetBooking->to_location_id)->value('name');

        $fromLocation = $pickupHotelName !== 'N/A'
            ? $pickupHotelName
            : Location::where('id', $fleetBooking->from_location_id)->value('name');

            // $baseRate = $rates->rate ?? 0;
            // $netCurrency = $rates->currency ?? '';
            
            // Get the country_id from from_location_id
            $countryId = Location::where('id', $fleetBooking->from_location_id)->value('country_id');
            $journey = $request->input('journey_type') === 'one_way' ? 'One Way' : ($request->input('journey_type') === 'two_way' ? 'Two Way' : 'N/A');
            // Find applicable surcharge for this country within time window
            $surcharge = Surcharge::where('country_id', $countryId)
                ->where('start_time', '<=', Carbon::parse($fleetBooking->pick_time)->format('H:i:s'))
                ->where('end_time', '>=', Carbon::parse($fleetBooking->pick_time)->format('H:i:s'))
                ->first();

        $returnPickupHotel = $returnPickupHotelName;
        $returnDropoffHotel = $returnDropoffHotelName;
        $bookingDate = convertToUserTimeZone($fleetBooking->created_at, 'F j, Y H:i T') ?? convertToUserTimeZone($request->input('booking_date'), 'F j, Y H:i T');
        $flight_iata_code = $request->input('depart_airline_code') ?? $request->input('arrival_airline_code') ?? 'N/A';
        $return_flight_iata_code = $request->input('return_depart_airline_code') ?? $request->input('return_arrival_airline_code');
        $flight_number = $request->input('depart_flight_number') ?: $fleetBooking->depart_flight_number ?: 'N/A';
        $arrival_flight_number = $request->input('arrival_flight_number') ?: $fleetBooking->arrival_flight_number ?: 'N/A';
        $return_flight_number = $request->input('return_depart_flight_number') ?: $fleetBooking->return_depart_flight_number;
        $return_arrival_flight_number = $request->input('return_arrival_flight_number') ?: $fleetBooking->return_arrival_flight_number;

        $fromLocationId = $request->input('from_location_id') ?? $fleetBooking->from_location_id;
        $toLocationId = $request->input('to_location_id') ?? $fleetBooking->to_location_id;
        //Assuming you have a relation between Rate and Location, fetch the currency from the Rate table
        $rate = Rate::where('from_location_id', $fromLocationId)->first();
        $fromLocationName = Location::find($fromLocationId);
        $toLocationName = Location::find($toLocationId);
        $haveMeetingPointDesc = "";
        $meeting_point_name = "";
        $meeting_point_images = [];

        $flight_arrival_terminal = $request->input('arrival_terminal') ?? $fleetBooking->arrival_terminal; // Get the meeting point ID from the request
        // dd($flight_arrival_terminal);
        // Retrieve meeting point details if a valid meeting point ID is provided
        if ($flight_arrival_terminal) {
            $meetingPoint = MeetingPoint::where('location_id', $fromLocationId)->where('terminal', $flight_arrival_terminal)->where('active', 1)->first() ?? MeetingPoint::where('location_id', $toLocationId)->where('terminal', $flight_arrival_terminal)->where('active', 1)->first();

            if ($meetingPoint) {

                // Retrieve meeting point details
                $haveMeetingPointDesc = $meetingPoint->meeting_point_desc ?? '';
                $meeting_point_name = $meetingPoint->meeting_point_name ?? '';
                // Decode and process attachments
                $meeting_point_attachment = $meetingPoint->meeting_point_attachments;
                if (is_string($meeting_point_attachment)) {
                    $decodedAttachment = json_decode($meeting_point_attachment, true);
                    // Make sure it's an array, even if there's only one attachment
                    $meeting_point_images = is_array($decodedAttachment) ? $decodedAttachment : [$meeting_point_attachment];
                }
                // Convert each image path to a base64-encoded string, if the file exists
                $meeting_point_images = array_map(function ($attachment) {
                    return [
                        'web_url' => url(str_replace('public/', 'storage/', $attachment)), // Public URL for web display
                        'pdf_url' => url(str_replace('public/', '', $attachment)), // Direct PDF URL
                        'base64' => file_exists(public_path(str_replace('public/', '', $attachment)))
                            ? 'data:image/' . pathinfo($attachment, PATHINFO_EXTENSION) . ';base64,' .
                            base64_encode(file_get_contents(public_path(str_replace('public/', '', $attachment))))
                            : null, // Base64 for embedding in PDFs
                    ];
                }, $meeting_point_images);
            }
        }


        $returnMeetingPointDesc = "";
        $return_meeting_point_name = "";
        $return_meeting_point_images = [];

        $return_flight_arrival_terminal = $request->input('return_arrival_terminal') ?? $fleetBooking->return_arrival_terminal; // Get the meeting point ID from the request

        if ($return_flight_arrival_terminal) {
            $returnMeetingPoint = MeetingPoint::where('location_id', $toLocationId)->where('terminal', $return_flight_arrival_terminal)->where('active', 1)->first() ?? MeetingPoint::where('location_id', $toLocationId)->where('terminal', $flight_arrival_terminal)->where('active', 1)->first();

            if ($returnMeetingPoint) {

                // Retrieve meeting point details
                $returnMeetingPointDesc = $returnMeetingPoint->meeting_point_desc ?? '';
                $return_meeting_point_name = $returnMeetingPoint->meeting_point_name ?? '';
                // Decode and process attachments
                $return_meeting_point_attachment = $returnMeetingPoint->meeting_point_attachments;
                if (is_string($return_meeting_point_attachment)) {
                    $decodedAttachment = json_decode($return_meeting_point_attachment, true);
                    // Make sure it's an array, even if there's only one attachment
                    $return_meeting_point_images = is_array($decodedAttachment) ? $decodedAttachment : [$return_meeting_point_attachment];
                }

                // Convert each image path to a base64-encoded string, if the file exists
                $return_meeting_point_images = array_map(function ($attachment) {
                    return [
                        'web_url' => url(str_replace('public/', 'storage/', $attachment)), // Public URL for web display
                        'pdf_url' => url(str_replace('public/', '', $attachment)), // Direct PDF URL
                        'base64' => file_exists(public_path(str_replace('public/', '', $attachment)))
                            ? 'data:image/' . pathinfo($attachment, PATHINFO_EXTENSION) . ';base64,' .
                            base64_encode(file_get_contents(public_path(str_replace('public/', '', $attachment))))
                            : null, // Base64 for embedding in PDFs
                    ];
                }, $return_meeting_point_images);
            }
        }
        $driver_phone_code = $request->input('phone_code') ?? optional($fleetBooking->driver)->phone_code ?? '';
        $driver_phone_number = $request->input('phone_number') ?? optional($fleetBooking->driver)->phone_number ?? '';
        $paymentMode = $fleetBooking->booking->payment_type;

        $voucherRedeem = VoucherRedemption::where('booking_id', $booking_status->id)->first();
        $discount = 0;
        $discountedPrice = 0;
        if ($voucherRedeem) {
            $discount = $voucherRedeem->discount_amount;
            $discountedPrice = str_replace(',', '', $fleetBooking->booking_cost) - $discount;
            // dd(str_replace(',', '', $tourBooking->total_cost),$discount);
        }

        return [
            'id' => $booking_status->booking_unique_id,
            'booking_id' => $booking_status->booking_unique_id ?? '',
            'meeting_point_desc' => $haveMeetingPointDesc ?? '',
            'meeting_point_name' => $meeting_point_name ?? '',
            'meeting_point_images' => $meeting_point_images ?? '',
            'return_meeting_point_desc' => $returnMeetingPointDesc ?? '',
            'return_meeting_point_name' => $return_meeting_point_name ?? '',
            'return_meeting_point_images' => $return_meeting_point_images ?? '',
            // 'meeting_point_desc_email' => $haveMeetingPointDescEmail ?? '',
            // 'meeting_point_name_email' => $meeting_point_name_email ?? '',
            // 'meeting_point_images_email' => $meeting_point_images_email ?? '',
            // 'return_meeting_point_desc_email' => $returnMeetingPointDescEmail ?? '',
            // 'return_meeting_point_name_email' => $return_meeting_point_name_email ?? '',
            // 'return_meeting_point_images_email' => $return_meeting_point_images_email ?? '',
            'fromLocationName' => $fromLocationName->name,
            'toLocationName' => $toLocationName->name,
            'passenger_full_name' => $request->input('passenger_full_name') ?? $fleetBooking->passenger_full_name,
            'passenger_contact_number' => ($request->input('phone_code') ?? $fleetBooking->phone_code) .
            ($request->input('passenger_contact_number') ?? $fleetBooking->passenger_contact_number),
            'passenger_email_address' => $request->input('passenger_email_address') ?? $fleetBooking->passenger_email_address,
            'booking_date' => $bookingDate,
            'pick_date' => Carbon::parse($fleetBooking->pick_date)->format('F j, Y') ?? Carbon::parse($fleetBooking->pick_date)->format('F j, Y'),
            'pick_time' => Carbon::parse($fleetBooking->pick_time)->format('H:i') .' '.$timezone_abbreviation ,
            'base_price' => $basePrice,
            'hours' => $request->input('hours'),
            'transfer_name' => $request->input('transfer_name'),
            'vehicle_luggage_capacity' => $request->input('vehicle_luggage_capacity') ?? $fleetBooking->vehicle_luggage_capacity,
            'vehicle_seating_capacity' => $request->input('vehicle_seating_capacity') ?? $fleetBooking->vehicle_seating_capacity,
            'journey_type' => $journey,
            'pickup_address' => $fromLocation,
            'dropoff_address' => $toLocation,
            'flight_departure_time' => $flight_departure_time,
            'flight_arrival_time' => $flight_arrival_time,
            'flight_iata_code' => $flight_iata_code,
            'flight_number' => $flight_number,
            'arrival_flight_number' => $arrival_flight_number,
            'return_flight_arrival_time' => $return_flight_arrival_time,
            'return_flight_departure_time' => $return_flight_departure_time,
            'return_flight_iata_code' => $return_flight_iata_code,
            'return_flight_number' => $return_flight_number,
            'return_arrival_flight_number' => $return_arrival_flight_number,
            'return_pickup_date' => $return_pickup_date,
            'return_pickup_time' => $return_pickup_time,
            'return_pickup_address' => $returnPickupHotel,
            'return_dropoff_address' => $returnDropoffHotel,
            'agent_voucher_no' => auth()->id(),
            'vehicle' => $vehicle ?? null,
            'agentLogo' => $agentLogo,
            'agentCompanyName' => $agentCompanyName,
            'agentCompanyNumber' => $agentCompanyNumber,
            'agentCompanyWebsite' => $agentCompanyWebsite,
            'agentCompanyCity' => $agentCompanyCity,
            'agentCompanyZip' => $agentCompanyZip,
            'hirerName' => $hirerName,
            'hirerPhone' => $hirerPhone,
            'hirerEmail' => $hirerEmail,
            'phone' => $phone,
            'agentCompanyAddress' => $agentCompanyAddress,
            'remarks' => $remarks,
            'booking_status' => $booking_status->booking_status,
            'deadlineDate' => Carbon::parse($booking_status->deadline_date)->format('F j, Y H:i') .' '.$timezone_abbreviation,
            'driver_name' => $request->input('name') ?? optional($fleetBooking->driver)->name ?? '',
            'driver_number' => $driver_phone_code . '' . $driver_phone_number,
            'vehicle_no' => $request->input('car_no') ?? optional($fleetBooking->driver)->car_no ?? '',
            'arrival_country' => Location::where('id', $fromLocationId)->first()->country->name ?? '',
            'is_updated' => $is_updated,
            'netRate' => $booking_status->net_rate,
            'netCurrency' => $booking_status->net_rate_currency,
            'package' => $request->input('package') ?? $fleetBooking->package,
            'paymentMode' => $paymentMode,
            'discountedPrice' => $discountedPrice,
            'discount' => $discount,
            'voucher' => $voucherRedeem ? $voucherRedeem->voucher : null,
            'currency' => $currency,
            'voucher_code' => $request->voucher_code,
        ];
    }

    public function createBookingPDF($bookingData, $email, Request $request, $fleetBooking)
    {
        $user = User::where('id', $fleetBooking ->user_id)->first();

        CreateBookingPDFJob::dispatch($bookingData, $email, $fleetBooking, $user);

        return true;
        $passengerName = $user->first_name . ' ' . $user->last_name;

        $directoryPath = public_path("bookings");
        // Create the directory if it doesn't exist
        if (!file_exists($directoryPath)) {
            mkdir($directoryPath, 0755, true); // Create the directory with permissions
        }
        // Create a unique name for the PDF using bookingId and current timestamp
        $timestamp = now()->format('Ymd'); // e.g., 20241023_153015
        $id = $fleetBooking->id;
        $pdfFilePathVoucher = "{$directoryPath}/booking_voucher_{$timestamp}_{$id}.pdf";
        $pdfFilePathInvoice = "{$directoryPath}/booking_invoice_{$timestamp}_{$id}.pdf";
        $pdfFilePathAdminVoucher = "{$directoryPath}/booking_admin_voucher_{$timestamp}_{$id}.pdf";

        // Load the view and save the PDF
        $pdf = Pdf::loadView('email.transfer.booking_voucher', $bookingData);
        $pdf->save($pdfFilePathVoucher);
        // booking_voucher
        $pdf = Pdf::loadView('email.transfer.booking_invoice', $bookingData);
        $pdf->save($pdfFilePathInvoice);
        //voucher to admin
        $pdf = Pdf::loadView('email.transfer.booking_to_admin_voucher', $bookingData);
        $pdf->save($pdfFilePathAdminVoucher);
        $cc = [config('mail.notify_transfer'), config('mail.notify_info'), config('mail.notify_account')];

        // $isCreatedByAdmin = $fleetBooking->created_by_admin; // to track creation by admin

        //Send the email with the attached PDF
        // Mail::to($email)->send(new BookingMail($bookingData, $pdfFilePathVoucher, $passengerName));

        // $mailInstance = new BookingMail($bookingData, $pdfFilePathVoucher, $passengerName);
        // SendEmailJob::dispatch($email, $mailInstance);

        // // Mail::to($email)->send(new TransferBookingInvoiceMail($bookingData, $pdfFilePathInvoice, $passengerName));
        // $mailInstance = new TransferBookingInvoiceMail($bookingData, $pdfFilePathInvoice, $passengerName);
        // SendEmailJob::dispatch($email, $mailInstance);

        // // Mail::to(['tours@grtravel.net', 'info@grtravel.net'])->send(new BookingMailToAdmin($bookingData, $pdfFilePathAdminVoucher, $passengerName));
        // $email = ['tours@grtravel.net', 'info@grtravel.net'];
        // $mailInstance = new BookingMailToAdmin($bookingData, $pdfFilePathAdminVoucher, $passengerName);
        // SendEmailJob::dispatch($email, $mailInstance);
    }

    public function sendVoucherEmail($request, $fleetBooking, $dropOffName, $pickUpName, $is_updated = 0)
    {
        $bookingData = $this->prepareBookingData($request, $fleetBooking, $dropOffName, $pickUpName, $is_updated);
        $user = User::where('id', $fleetBooking->user_id)->first();
        $passengerName = $user->first_name . ' ' . $user->last_name;
        $directoryPath = public_path("bookings");
        // Create the directory if it doesn't exist
        if (!file_exists($directoryPath)) {
            mkdir($directoryPath, 0755, true); // Create the directory with permissions
        }
        // Create a unique name for the PDF using bookingId and current timestamp
        $timestamp = now()->format('Ymd'); // e.g., 20241023_153015
        $id = $fleetBooking->id;

        $pdfFilePathInvoice = "{$directoryPath}/booking_invoice_{$timestamp}_{$id}.pdf";
        $pdfFilePathVoucher = "{$directoryPath}/booking_voucher_{$timestamp}_{$id}.pdf";
        $pdfFilePathAdminVoucher = "{$directoryPath}/booking_admin_voucher_{$timestamp}_{$id}.pdf";

        // booking_voucher
        $pdf = Pdf::loadView('email.transfer.booking_invoice', $bookingData);
        $pdf->save($pdfFilePathInvoice);

        $pdfVoucher = Pdf::loadView('email.transfer.booking_voucher', $bookingData);
        $pdfVoucher->save($pdfFilePathVoucher);

        $pdfAdmin = Pdf::loadView('email.transfer.booking_to_admin_voucher', $bookingData);
        $pdfAdmin->save($pdfFilePathAdminVoucher);

        $email = $user->email;

        // $isCreatedByAdmin = $fleetBooking->created_by_admin; // to track creation by admin

        // Mail::to($email)->send(new TransferBookingInvoiceMail($bookingData, $pdfFilePathInvoice, $passengerName));
        $mailInstance = new TransferBookingInvoiceMail($bookingData, $pdfFilePathInvoice, $passengerName);
        if($is_updated === 0){
            SendEmailJob::dispatch($email, $mailInstance);
        }

        $mailInstance = new BookingMail($bookingData, $pdfFilePathVoucher, $passengerName);
        SendEmailJob::dispatch($email, $mailInstance);
        
        if (auth()->user()->type !== 'admin') {
            $email = [config('mail.notify_transfer'), config('mail.notify_info'), config('mail.notify_account')];
            $mailInstance = new BookingMailToAdmin($bookingData, $pdfFilePathAdminVoucher, $passengerName);
            SendEmailJob::dispatch($email, $mailInstance);
        }
    }

    public function sendInvoiceAndVoucher($booking)
    {
        // Prepare booking data for the emails
        $bookingData = $this->prepareBookingData(request(), $booking, $booking->dropoff_name, $booking->pickup_name);

        // Generate and send the invoice PDF
        $this->createBookingPDF($bookingData, $booking->email, request(), $booking);
    }
}
