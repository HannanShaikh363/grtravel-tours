<?php

namespace App\Services;
use App\Models\ContractualHotel;
use Illuminate\Http\Request; 
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Models\User;
use App\Models\AgentPricingAdjustment;
use App\Models\ContractualHotelRate;
use App\Models\HotelSurcharge;
use App\Models\CurrencyRate;
use App\Models\Booking;
use App\Models\ContractualHotelBooking;
use App\Models\ContractualRoomDetail;
use App\Models\VoucherRedemption;
use App\Models\ContractualRoomPassengerDetail;
use App\Models\Country;
use App\Models\Company;
use App\Models\City;
use App\Mail\ContractualHotel\HotelBookingRequest;
use App\Mail\ContractualHotel\HotelVoucherToAdminMail;
use App\Mail\ContractualHotel\HotelBookingVoucherMail;
use App\Mail\ContractualHotel\HotelBookingInvoiceMail;
use Illuminate\Support\Facades\DB;
use ProtoneMedia\Splade\Facades\Toast;
use App\Mail\BookingApprovalPending;
use App\Jobs\SendEmailJob;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Validator;

use DateTime;


class ContractualHotelService
{

     public function extractRequestParameters(Request $request)
	{
		
	    $roomCount      = $request->input('rooms', 1);
	    $adultCapacities = $request->input('adult_capacity', []); // array keyed by room
	    $childCapacities = $request->input('child_capacity', []); // array keyed by room
	    $childAgesByRoom = $request->input('child_ages', []);     // nested array by room

	    $roomDetails = [];

	    for ($i = 1; $i <= $roomCount; $i++) {
	        $adultCapacity = $adultCapacities[$i] ?? 1;
	        $childCapacity = $childCapacities[$i] ?? 0;
	        $roomChildAges = [];

	        if (isset($childAgesByRoom[$i])) {
	            $roomChildAges = array_values($childAgesByRoom[$i]);
	        }

	        $roomDetails[] = [
	            'room_number'    => $i,
	            'adult_capacity' => $adultCapacity,
	            'child_capacity' => $childCapacity,
	            'child_ages'     => $roomChildAges,
	        ];
	    }

	        return [
	            'check_in_out' => $request->input('check_in_out'),
	            'rooms' => $request->input('rooms', 1),
	            'location' => $request->input('city')['name'],
	            'room_details' => $roomDetails, // Properly structured room details
	            'price' => $request->input('price'),
	           'hotel_name'   => $request->input('hotel_name') ?? $request->input('city')['name'],
	            'hotel_id'=>$request->input('city')['id'],
	            'type'=>$request->input('city')['type'],
	            'currency' => $request->input('currency'),
                'search_type' => $request->input('search_type')
	        ];
    }
    public function buildQuery(array $parameters)
    {
        $query = ContractualHotel::with([
            'cityRelation',
            'countryRelation'
        ])
        ->select(
            'contractual_hotels.id',
            'contractual_hotels.hotel_name as hotel_name',
            'contractual_hotels.country_id',
            'contractual_hotels.city_id',
            'contractual_hotels.description',
            'contractual_hotels.images',
            'contractual_hotels.property_amenities',
            'contractual_hotel_rates.id as rate_id',
            'contractual_hotel_rates.room_type',
            'contractual_hotel_rates.room_capacity',
            'contractual_hotel_rates.effective_date',
            'contractual_hotel_rates.expiry_date',
            'contractual_hotel_rates.hotel_id',
            'contractual_hotel_rates.weekdays_price',
            'contractual_hotel_rates.weekend_price',
            'contractual_hotel_rates.currency',
            'contractual_hotel_rates.no_of_beds',
            'contractual_hotel_rates.images as rate_images',
            'hotel_surcharges.type',
            'hotel_surcharges.value as surcharge_amount',
            'hotel_surcharges.start_date',
            'hotel_surcharges.end_date'
        )
        ->leftJoin('contractual_hotel_rates', 'contractual_hotels.id', '=', 'contractual_hotel_rates.hotel_id')
        ->leftJoin('hotel_surcharges', 'contractual_hotels.id', '=', 'hotel_surcharges.hotel_id');

        // Filter by type
        if (!empty($parameters['type']) && $parameters['type'] == 'hotel') {
            $query->where('contractual_hotels.hotel_name', $parameters['hotel_name']);
        }
        if (!empty($parameters['type']) && $parameters['type'] == 'city') {
            $query->where('contractual_hotels.city_id', $parameters['hotel_id']);
        }


        // Filter by room capacity
        if (!empty($parameters['room_details'])) {
            $query->where(function ($q) use ($parameters) {
                foreach ($parameters['room_details'] as $room) {
                    $totalCapacity = ($room['adult_capacity'] ?? 0) + ($room['child_capacity'] ?? 0);
                    $q->where('contractual_hotel_rates.room_capacity', '>=', $totalCapacity);
                }
            });
        }

        // Order
        $query->orderBy('contractual_hotel_rates.room_capacity', 'asc');

        $results = $query->get();
        // Extract check-in/out dates
        $decodedDateRange = urldecode($parameters['check_in_out']);
        $dates = explode(' to ', $decodedDateRange);
        $checkIn = Carbon::parse(trim($dates[0]));
        $checkOut = Carbon::parse(trim($dates[1] ?? $dates[0])); // fallback to same day if no end

        // If same day, count as 1 night
        if ($checkIn->equalTo($checkOut)) {
            $checkOut = $checkOut->copy()->addDay();
        }

        $numRooms = count($parameters['room_details']);
        $filteredResults = [];
        foreach ($results as $result) {
            $totalPrice = 0;

            // Loop through each night between check-in and check-out (excluding last day)
            $period = new \DatePeriod(
                $checkIn,
                new \DateInterval('P1D'),
                $checkOut // end is exclusive
            );

            foreach ($period as $date) {
                $dayOfWeek = $date->format('w'); // 0 = Sun, 6 = Sat
                if ($dayOfWeek == 6 || $dayOfWeek == 0) {
                    $totalPrice += $result->weekend_price ?? 0;
                } else {
                    $totalPrice += $result->weekdays_price ?? 0;
                }
            }
            // Apply surcharges
            if (!empty($result->surcharge_amount)) {
                $surchargeAmount = $this->calculateHotelSurcharges(
                    $result, // pass model directly
                    $checkIn,
                    $checkOut
                );
                $totalPrice += $surchargeAmount;
            }


            // Multiply by rooms
            $result->total_price = $totalPrice * $numRooms;

            $filteredResults[] = $result;
        }
        

        // Filter by effective & expiry date
       // Filter by effective & expiry date
    $filteredResults = collect($filteredResults)->filter(function ($result) use ($checkIn, $checkOut) {
        $effectiveDate = Carbon::parse($result->effective_date);
        $expiryDate = Carbon::parse($result->expiry_date);
        return $checkIn->greaterThanOrEqualTo($effectiveDate) && $checkOut->lessThanOrEqualTo($expiryDate);
    });

    // ✅ Group by hotel_id and keep only the cheapest rate
    $filteredResults = $filteredResults
        ->groupBy('hotel_id')
        ->map(function ($group) {
            return $group->sortBy('total_price')->first();
        })
        ->values();

    return $filteredResults;

    }


    public function applyFiltersAndPaginate($results, array $parameters)
    {
        // $parameters['search_type']='';
        // echo "<pre>";print_r($parameters);die();
        // Filter by price range
        if (!empty($parameters['price'])) {
            $priceRange = $this->extractPriceRange($parameters['price']);
            if ($priceRange) {
                $currentCurrency = $parameters['currency'] ?? 'MYR'; // Default to MYR
                $convertedPriceRange = $this->convertPriceRangeToMYR($priceRange, $currentCurrency);
                if ($convertedPriceRange) {
                    $results = $results->whereBetween('total_price', $convertedPriceRange);
                }
            }
        }

        // Filter by hours
        if (!empty($parameters['hour'])) {
            $results = $results->where('hours', $parameters['hour']);
        }

        // Filter by package
        if (!empty($parameters['package'])) {
            $results = $results->whereIn('package', $parameters['package']);
        }

        // Filter by location_id
        if (!empty($parameters['location_id'])) {
            $results = $results->where('location_id', $parameters['location_id']);
        }
        // echo "<pre>";print_r($results);die();
        // Filter by hotel name only if search type is "hotel"
        if (!empty($parameters['hotel_name']) && $parameters['search_type'] == 'hotel') {
            // echo "<pre>";print_r(2);die();
            $hotelNames = is_array($parameters['hotel_name'])
                ? $parameters['hotel_name']
                : explode(',', $parameters['hotel_name']);
            $results = $results->whereIn('hotel_name', $hotelNames);
        }
        // echo "<pre>";print_r($results);die();
        // ✅ Sort by total_price
        $results = $results->sortBy('total_price');

        // Group by hotel name
        $groupedResults = $results->groupBy('hotel_name');

        // Pagination
        $page = request()->input('page', 1);
        $perPage = 10;
        $pagedGroupedResults = $groupedResults->slice(($page - 1) * $perPage, $perPage);

        return new LengthAwarePaginator(
            $pagedGroupedResults,
            $groupedResults->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => $parameters]
        );
    }

 
    public function applyCurrencyConversion($rate, $currentCurrency, $targetCurrency)
    {
        if ($targetCurrency) {
            $usdRate = CurrencyService::convertCurrencyToUsd($currentCurrency, $rate);
            return round(CurrencyService::convertCurrencyFromUsd($targetCurrency, $usdRate), 2);
        }
        return $rate;
    }
        public function extractPriceRange($priceRanges)
    {
        $boundaries = [];
        foreach ($priceRanges as $range) {
            $parts = explode('_', $range);
            $boundaries[] = (int) $parts[0];
            if (isset($parts[1])) {
                $boundaries[] = (int) $parts[1];
            }
        }
        return [min($boundaries), max($boundaries)];
    }
        private function convertPriceRangeToMYR(array $priceRange, string $currentCurrency)
    {
        $convertedRange = [
            $this->applyCurrencyConversion($priceRange[0], $currentCurrency, 'MYR'),
            $this->applyCurrencyConversion($priceRange[1], $currentCurrency, 'MYR'),
        ];

        return $convertedRange;
    }


    public function adjustHotel($rates, array $parameters, $type = null)
    {

        $agentCode = auth()->user()->agent_code;

        $agentId = User::where('agent_code', $agentCode)
            ->where('type', 'agent')
            ->value('id');

        $adjustmentRates = AgentPricingAdjustment::getCurrentAdjustmentRate($agentId);
        if ($type == 'hotelView') {

            foreach ($rates as $rate) {
                // Apply currency conversion
                $rate->total_price = $this->applyCurrencyConversion($rate->total_price, $rate->currency, $parameters['currency']);
                // $rate->total_ticketprice = $this->applyCurrencyConversion($rate->total_ticketprice, $rate->currency, $parameters['currency']);
                $rate->adult = $this->applyCurrencyConversion($rate->adult, $rate->currency, $parameters['currency']);
                $rate->child = $this->applyCurrencyConversion($rate->child, $rate->currency, $parameters['currency']);
                // Loop through all adjustment rates
                foreach ($adjustmentRates as $adjustmentRate) {
                    if ($adjustmentRate->transaction_type === 'contractual_hotel') {
                        $rate->total_price = round($this->applyAdjustment($rate->total_price, $adjustmentRate), 2);
                    }
                }
            }
        } else {
            foreach ($rates as $gentingName => $group) {

                foreach ($group as $rate) {
                    // Apply currency conversion

                    $rate->total_price = $this->applyCurrencyConversion($rate->total_price, $rate->currency, $parameters['currency']);
                    // $rate->total_ticketprice = $this->applyCurrencyConversion($rate->total_ticketprice, $rate->currency, $parameters['currency']);
                    $rate->adult = $this->applyCurrencyConversion($rate->adult, $rate->currency, $parameters['currency']);
                    $rate->child = $this->applyCurrencyConversion($rate->child, $rate->currency, $parameters['currency']);
                    // Loop through all adjustment rates
                    foreach ($adjustmentRates as $adjustmentRate) {
                        if ($adjustmentRate->transaction_type === 'contractual_hotel') {
                            $rate->total_price = round($this->applyAdjustment($rate->total_price, $adjustmentRate), 2);
                        }
                    }
                }
            }
        }
        // echo "<pre>";print_r($rates);die();

        return $rates;
    }
    
    public function applyAdjustment($rate, $adjustmentRate)
    {
        // Check if $adjustmentRate is a valid object with the expected properties
        if ($adjustmentRate && isset($adjustmentRate->active, $adjustmentRate->percentage, $adjustmentRate->percentage_type)) {
            if ($adjustmentRate->active !== 0) {
                $percentage = $adjustmentRate->percentage;

                // Apply the adjustment based on percentage_type
                return $adjustmentRate->percentage_type === 'surcharge'
                    ? $rate + ($rate * ($percentage / 100))
                    : $rate - ($rate * ($percentage / 100));
            }
        }

        return $rate;
    }
    public function getFilters(array $parameters, $adjustedRates)
    {
        // echo "<pre>";print_r($parameters);die();
        if ($parameters['type']=='city') {
            // Fetch the location_id based on the provided search location
            $destinations = ContractualHotel::where('city_id', $parameters['hotel_id'])->pluck('hotel_name');
           
        } else {
            // If searchLocation is empty, return all or handle as needed
            $destinations = collect([]);
        }

        // Fetch distinct packages directly from Genting Packages
        // $vehicleTypes = ContractualHotelRate::join('genting_packages', 'contractual_hotel_rates.genting_package_id', '=', 'genting_packages.id')
        //     ->distinct()
        //     ->pluck('genting_packages.package');
        // echo "<pre>";print_r($destinations);die();
        // Extract total_price from $adjustedRates
        $prices = $adjustedRates->flatMap(function ($group) {
            return $group->pluck('price');
        })->map(fn($price) => (int) $price)->unique()->values();

        return [
            'destinations' => $destinations,
            // 'vehicleTypes' => $vehicleTypes,
            'prices' => $prices,
        ];
    }


private function calculateHotelSurcharges($hotel, $checkIn, $checkOut)
{
    // If array given, use first element
    if (is_array($hotel)) {
        $hotel = reset($hotel);
    }

    $checkInDate  = Carbon::parse($checkIn);
    $checkOutDate = Carbon::parse($checkOut);
    $nights       = $checkInDate->diffInDays($checkOutDate);
    $totalSurcharge = 0;

    // Get surcharges for this hotel
    $surcharges = HotelSurcharge::where('hotel_id', $hotel->id)
        ->where('type', 'surcharge')
        ->get();

    foreach ($surcharges as $s) {

        // 1. Minimum nights check
        if (!empty($s->minimum_nights) && $nights < $s->minimum_nights) {
            continue;
        }

        // 2. Validity type check
        if ($s->validity_type === 'in_days') {
        $validFrom = $checkInDate->copy();
        $daysToAdd = (int) $s->fixed_days; // ✅ force integer
        $validTo   = $validFrom->copy()->addDays($daysToAdd);

            if ($checkInDate->lt($validFrom) || $checkInDate->gt($validTo)) {
                continue;
            }
        }
        elseif ($s->validity_type === 'date_range') {
            if (
                ($s->start_date && $checkInDate->lt(Carbon::parse($s->start_date))) ||
                ($s->end_date && $checkInDate->gt(Carbon::parse($s->end_date)))
            ) {
                continue;
            }
        }

        // 3. Non-applicable dates check
        if ($s->not_applicable_start && $s->not_applicable_end) {
            $naStart = Carbon::parse($s->not_applicable_start);
            $naEnd   = Carbon::parse($s->not_applicable_end);

            if (
                $checkInDate->between($naStart, $naEnd) ||
                $checkOutDate->between($naStart, $naEnd)
            ) {
                continue;
            }
        }

        // 4. Calculate surcharge
        if ($s->amount_type === 'amount') {
            $totalSurcharge += $s->value;
        } elseif ($s->amount_type === 'percentage') {
            $bookingTotal = 0;

            // Loop through each night and sum base price
            $period = CarbonPeriod::create($checkInDate, $checkOutDate->copy()->subDay());
            foreach ($period as $date) {
                $dayOfWeek = $date->format('w'); // 0 = Sun, 6 = Sat
                if ($dayOfWeek == 6 || $dayOfWeek == 0) {
                    $bookingTotal += $hotel->weekend_price ?? 0;
                } else {
                    $bookingTotal += $hotel->weekdays_price ?? 0;
                }
            }

            $totalSurcharge += ($bookingTotal * $s->value / 100);
        }
    }
    // echo "<pre>";print_r($totalSurcharge);die();
    return $totalSurcharge;
}






    private function getDateRange($start, $end)
    {
        $dates = [];
        $current = Carbon::parse($start);
        $endDate = Carbon::parse($end);

        while ($current <= $endDate) {
            $dates[] = $current->copy();
            $current->addDay();
        }

        return $dates;
    }

    private function isSurchargeApplicable($surcharge, $date)
    {
        // Example: only apply for specific validity type
        if ($surcharge->validity_type === 'date_range') {
            return $date->between(Carbon::parse($surcharge->start_date), Carbon::parse($surcharge->end_date));
        }

        return false;
    }

    private function calculateAmount($surcharge, $currency)
    {
        if ($surcharge->amount_type === 'percentage') {
            return $surcharge->value . '%';
        }

        return $currency . ' ' . number_format($surcharge->value, 2);
    }
    public function hotelFormValidationArray(Request $request): array
    {
        $rules = [
            "currency" => ['required'],
            "check_in" => ['required'],
            "check_out" => ['required'],
            // "total_cost" => ['required'],
        ];

        $passengers = $request->input('passengers', []);
        $messages = [];

        // Iterate over rooms and their passengers
        foreach ($passengers as $roomIndex => $roomPassengers) {
            foreach ($roomPassengers as $passengerIndex => $passenger) {
                // If it's the first passenger of the room, make nationality_id required
                if ($passengerIndex === 0) {
                    // Dynamically add the nationality_id rule for the first passenger of each room
                    $rules["passengers.$roomIndex.$passengerIndex.nationality_id"] = ['required'];

                    // Add custom message for first passenger nationality_id
                    $messages["passengers.$roomIndex.$passengerIndex.nationality_id.required"] = "Nationality is missing of first traveller of room " . ($roomIndex + 1) . ".";
                }
            }
        }

        return ['rules' => $rules, 'messages' => $messages];
    }
    public function storeBooking(Request $request)
    {
        // echo "<pre>";print_r($request->all());die;
        $validator = Validator::make($request->all(), [
            'hotel_id'        => 'required|exists:contractual_hotels,id',
            'check_in'        => 'required|date|after_or_equal:today',
            'check_out'       => 'required|date|after:check_in',
            'number_of_rooms' => 'required|integer|min:1',
            'currency'        => 'required|string',
            'rate_id'         => 'required|exists:contractual_hotel_rates,id',
            'passengers'      => 'required|array'
        ]);
       $rooms = $request->input('room_details') ?? $request->input('roomDetails');
        if (!is_array($rooms)) {
            $rooms = json_decode($rooms, true);
        }
        if (empty($rooms)) {
            $rooms = [
                [
                    "room_number"    => 1,
                    "adult_capacity" => "1",
                    "child_capacity" => "0",
                    "child_ages"     => [],
                ]
            ];
        }

        $rawPassengers = $request->input('passengers', []);

        $passengerData = collect($rawPassengers)->map(function ($roomPassengersRaw) {
            // Extract extra bed values if they exist
            $extraBedAdult = is_array($roomPassengersRaw) && isset($roomPassengersRaw['extra_bed_adult'])
                ? (int) $roomPassengersRaw['extra_bed_adult']
                : 0;

            $extraBedChild = is_array($roomPassengersRaw) && isset($roomPassengersRaw['extra_bed_child'])
                ? (int) $roomPassengersRaw['extra_bed_child']
                : 0;

            // Remove extra_bed_* from the array to just keep the passengers
            $roomPassengers = collect($roomPassengersRaw)->filter(fn($item) => is_array($item))->values();

            return [
                'extra_bed_adult' => $extraBedAdult,
                'extra_bed_child' => $extraBedChild,
                'passengers' => $roomPassengers
                    ->filter(function ($passenger) {
                        return !empty($passenger['full_name'])
                            || !empty($passenger['phone_code'])
                            || !empty($passenger['nationality_id']);
                    })
                    ->values()
                    ->toArray()
            ];
        })->filter(fn($room) => !empty($room['passengers']))->values()->toArray();

        // Attach passengers + extra bed info to respective rooms
        foreach ($rooms as $index => &$room) {
            $room['passengers']       = $passengerData[$index]['passengers'] ?? [];
            $room['extra_bed_adult']  = $passengerData[$index]['extra_bed_adult'] ?? 0;
            $room['extra_bed_child']  = $passengerData[$index]['extra_bed_child'] ?? 0;
        }


        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();
        $user = auth()->user();
        $payment_type='';
        $hotel = ContractualHotel::findOrFail($data['hotel_id']);
        $rate  = ContractualHotelRate::findOrFail($data['rate_id']);

        $checkIn  = Carbon::parse($data['check_in']);
        $checkOut = Carbon::parse($data['check_out']);
        $numNights = $checkIn->diffInDays($checkOut);

        // --- Step 1: Base price (weekday/weekend) ---
        $totalPrice = 0;
        $currentDate = $checkIn->copy();
        while ($currentDate->lt($checkOut)) {
            $dayOfWeek = $currentDate->format('w'); // 0 = Sun, 6 = Sat
            $totalPrice += ($dayOfWeek == 6 || $dayOfWeek == 0)
                ? ($rate->weekend_price ?? 0)
                : ($rate->weekdays_price ?? 0);
            $currentDate->addDay();
        }


        // --- Step 2: Add surcharge ---
        $surcharge = $this->calculateSurcharge(
            $hotel->id,
            $checkIn,
            $checkOut,
            $totalPrice / max($numNights, 1)
        );
        $netRate= ($totalPrice + $surcharge )* $data['number_of_rooms'];
        $totalPrice = ($totalPrice + $surcharge) * $data['number_of_rooms'];
        $booking_status = 'confirmed';
        // --- Step 3: Currency conversion ---
        $convertedPrice = app('App\Services\ContractualHotelService')
            ->applyCurrencyConversion($totalPrice, $rate->currency, $data['currency']);

        // --- Step 4: Agent adjustment ---
        $agentId = User::where('agent_code', $user->agent_code)
            ->where('type', 'agent')
            ->value('id');

        $adjustmentRate = AgentPricingAdjustment::getCurrentAdjustmentRate($agentId);
        if ($adjustmentRate && $adjustmentRate->isNotEmpty()) {
            $hotelRates = $adjustmentRate->filter(function ($rateItem) {
                return $rateItem->transaction_type === 'contractual_hotel';
            });

            foreach ($hotelRates as $hotelRate) {
                $convertedPrice = app('App\Services\ContractualHotelService')
                    ->applyAdjustment($convertedPrice, $hotelRate);
                    $originalRate = app('App\Services\ContractualHotelService')
                    ->applyAdjustment($netRate, $hotelRate);
            }
        }   
        // echo "<pre>";print_r($originalRate);
        // echo "<pre>";print_r($netRate);
        // die;
        // --- Step 5: Add extra bed charges last ---
        $extraBedAdultQty   = 0;
        $extraBedChildQty   = 0;
        $extraBedAdultTotal = 0;
        $extraBedChildTotal = 0;

        if (!empty($data['passengers']) && is_array($data['passengers'])) {
            foreach ($data['passengers'] as $passengerRoom) {
                $adultQty = (int)($passengerRoom['extra_bed_adult'] ?? 0);
                $childQty = (int)($passengerRoom['extra_bed_child'] ?? 0);

                if ($adultQty > 0) {
                    $extraBedAdultQty   += $adultQty;
                    $extraBedAdultTotal += $adultQty * ($hotel->extra_bed_adult ?? 0) * $numNights;
                }

                if ($childQty > 0) {
                    $extraBedChildQty   += $childQty;
                    $extraBedChildTotal += $childQty * ($hotel->extra_bed_child ?? 0) * $numNights;
                }
            }
        }
        // ✅ Convert both extra bed totals into booking currency
        $convertedAdultExtra = $this->applyCurrencyConversion($extraBedAdultTotal, $hotel->currency, $data['currency']);
        $convertedChildExtra = $this->applyCurrencyConversion($extraBedChildTotal, $hotel->currency, $data['currency']);
        $currencyRate = CurrencyRate::where('target_currency', $data['currency'])->first();
        $originalCurrencyRate = CurrencyRate::where('target_currency', $rate->currency)->first();
        $action = $request->input('submitButton'); // This will be 'request_booking'
                if ($action === 'request_booking') {
                    $booking_status = 'pending_approval';
                }
        // ✅ Add to already converted base price
        $convertedPrice += $convertedAdultExtra + $convertedChildExtra;
        // --- Step 6: Save booking ---
        // echo "<pre>";print_r($data);die();
        
        $bookingData = [
            'agent_id' => auth()->id(),
            'user_id' => auth()->id(),
            'booking_date' => now()->format('Y-m-d H:i:s'),
            'amount' => $convertedPrice,
            'currency' => $data['currency'],
            'service_date' => $request->input('check_in'),
            'booking_type' => 'contractual_hotel',
            'booking_status' => $booking_status,
            'payment_type' => $payment_type,
            'conversion_rate' => $currencyRate->rate, //done
            'original_rate' => $originalRate + $extraBedAdultTotal + $extraBedChildTotal,//done
            'original_rate_conversion' => $originalCurrencyRate->rate,
            'original_rate_currency' => $rate->currency,//done
            'net_rate' => $netRate, //done
            'net_rate_currency' => $rate->currency, //done
        ];
        $bookingSaveData = Booking::create($bookingData);
        $Hotelbooking = [
       
        'country_id'=>$hotel->country_id,
        'city_id'=>$hotel->city_id,
        'rate_id'=>$rate->id,
        'hotel_id'=>$hotel->id,
        'booking_id'=>$bookingSaveData->id,
        'user_id'=>auth()->id(),
        'hotel_name'=>$hotel->hotel_name,
        'check_in'=>$data['check_in'],
        'check_out'=>$data['check_out'],
        'currency'=>$data['currency'],
        'room_type'=>$rate->room_type,
        'total_cost'=>$convertedPrice,
        'number_of_rooms'=>$data['number_of_rooms'],
        'room_capacity'=>$rate->room_capacity,
        'extra_beds_adult'=>$extraBedAdultQty,
        'extra_beds_child'=>$extraBedChildQty,
        'extra_amount_adult_bed'=>$convertedAdultExtra,
        'extra_amount_child_bed'=>$convertedChildExtra,
        ];
        $hotelBooking = ContractualHotelBooking::create($Hotelbooking);
        
         $this->saveContractualRoomDetails($rooms, $hotelBooking->id);
        // dd($request->all(),$bookingData, $gentingBookingData,$rooms);
        try {

            DB::beginTransaction();
            // Approve the booking
            $hotelBooking->update(['approved' => true]);
            // $hotelBooking->update(['total_cost' => $convertedPrice]);

            // Check if the authenticated user is an admin
            $isCreatedByAdmin = auth()->check() && auth()->user()->hasRole('admin'); // Assuming you're using a roles system
            // If created by admin, mark as approved
            if ($isCreatedByAdmin) {
                $bookingSaveData->update(['created_by_admin' => true, 'booking_type_id' => $hotelBooking->id]);
                $hotelBooking->update(['created_by_admin' => true, 'approved' => true, 'booking_id' => $bookingSaveData->id]);
            } else {
                $hotelBooking->update(['booking_id' => $bookingSaveData->id]);
                $bookingSaveData->update(['booking_type_id' => $hotelBooking->id]);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollback(); // Roll back if there's an error.

            Toast::title($e->getMessage())
                ->danger()
                ->rightBottom()
                ->autoDismiss(5);

            if ($request->ajax()) {
                return response()->json(['success' => false, 'message' => $e], 422);
            }

            return Redirect::back()->withErrors(['error' => $e]);
        }
        $bookingSaveData->load('user.company');
        //only use when difference is 12 hours
        if ($action === 'request_booking') {
            // Update booking approval status to pending
            $hotelBooking->approved = 0;
            $hotelBooking->sent_approval = 1;
            $admin = User::where('type', 'admin')->first();
            // Save the changes
            $hotelBooking->save();
            // Send approval pending email to the agent
            $agentEmail = auth()->user()->email;
            $agentName = auth()->user()->first_name;

            $bookingType = $bookingSaveData->booking_type;
            $mailInstance = new BookingApprovalPending($hotelBooking, $agentName, $bookingSaveData);
            SendEmailJob::dispatch($agentEmail, $mailInstance);
            $is_updated = null;
            $bookingData = $this->prepareBookingData($request, $hotelBooking, $is_updated);
            $mailInstance = new HotelBookingRequest($hotelBooking, $bookingData, $admin->first_name, $hotelBooking->contractualRate);
            SendEmailJob::dispatch($admin->email, $mailInstance);
            SendEmailJob::dispatch(config('mail.notify_genting'), $mailInstance);
            SendEmailJob::dispatch(config('mail.notify_info'), $mailInstance);
            SendEmailJob::dispatch(config('mail.notify_account'), $mailInstance);
        } else {
            $is_updated = null;
            // Prepare data for PDF
            $bookingData = $this->prepareBookingData($request, $hotelBooking, $is_updated);
            $passenger_email = $request->input('passenger_email_address');
            $hirerEmail = $user->email;

            // Create and send PDF
            $this->createBookingPDF($bookingData, $hirerEmail, $request, $hotelBooking);

            if ($request->submitButton == "pay_offline") {

                Toast::title('Payment Done.')
                    ->danger()
                    ->rightBottom()
                    ->autoDismiss(5);
            }
        }
        session()->flash('success', 'Booking Request Submitted!');

        return response()->json(['success' => true, 'message' => 'Booking Created'], 200);
    }


    
    private function calculateSurcharge($hotelId, $checkIn, $checkOut, $baseNightlyPrice = 0)
    {
        $surcharges = HotelSurcharge::where('hotel_id', $hotelId)->get();
        if ($surcharges->isEmpty()) {
            return 0;
        }

        $total = 0;
        $bookingNights = $checkIn->diffInDays($checkOut);

        foreach ($surcharges as $surcharge) {
            // Skip if booking nights less than minimum nights
            if (!empty($surcharge->minimum_nights) && $bookingNights < $surcharge->minimum_nights) {
                continue;
            }

            // Loop each booking date
            for ($date = $checkIn->copy(); $date->lt($checkOut); $date->addDay()) {

                // Skip if in "Not Applicable" range
                if (!empty($surcharge->not_applicable_start) && !empty($surcharge->not_applicable_end)) {
                    if ($date->between(Carbon::parse($surcharge->not_applicable_start), Carbon::parse($surcharge->not_applicable_end))) {
                        continue;
                    }
                }

                $isValid = false;

                // Check validity type
                if ($surcharge->validity_type === 'in_days') {
                    // For in_days type, use booking check-in date as the start
                    $start = $checkIn->copy();
                    $fixedDays = (int)($surcharge->fixed_days ?? 0);
                    $validUntil = $start->copy()->addDays($fixedDays);

                    if ($date->between($start, $validUntil)) {
                        $isValid = true;
                    }
                } 
                elseif ($surcharge->validity_type === 'date_range') {
                    if (!empty($surcharge->start_date) && !empty($surcharge->end_date)) {
                        if ($date->between(Carbon::parse($surcharge->start_date), Carbon::parse($surcharge->end_date))) {
                            $isValid = true;
                        }
                    }
                }

                if (!$isValid) {
                    continue;
                }

                // Apply surcharge or discount
                $amount = 0;
                if ($surcharge->amount_type === 'amount') {
                    $amount = (float)$surcharge->value;
                } elseif ($surcharge->amount_type === 'percentage') {
                    $amount = ($baseNightlyPrice * (float)$surcharge->value) / 100;
                }

                if ($surcharge->type === 'discount') {
                    $total -= $amount;
                } else {
                    $total += $amount;
                }
            }
        }

        return $total;
    }
     public function saveContractualRoomDetails(array $rooms, $bookingID)
    {
        // echo "<pre>";print_r($bookingID);
        // echo "<pre>";print_r($rooms);die();
        foreach ($rooms as $room) {
            $passengers = $room['passengers'] ?? [];
            $childAges = $room['child_ages'] ?? [];
            $adultCount = (int) ($room['adult_capacity'] ?? 0);
            $childCount = (int) ($room['child_capacity'] ?? 0);
            $extra_bed_adult = (int) ($room['extra_bed_adult'] ?? 0);
            $extra_bed_child = (int) ($room['extra_bed_child'] ?? 0);

            // Use the first passenger's info for genting_room_details
            $firstPassenger = $passengers[0] ?? [];

            $roomDetail = ContractualRoomDetail::create([
                'room_no' => $room['room_number'] ?? null,
                'booking_id' => $bookingID,
                'number_of_adults' => $adultCount,
                'number_of_children' => $childCount,
                'extra_bed_for_adult' => $extra_bed_adult,
                'extra_bed_for_child' => $extra_bed_child,
                'child_ages' => !empty($childAges) ? json_encode($childAges) : null,
            ]);

            // Save all passengers in genting_room_passenger_details
            $firstNationalityId = $firstPassenger['nationality_id'] ?? null;

            foreach ($passengers as $passenger) {
                $nationalityId = $passenger['nationality_id'] ?? $firstNationalityId;

                ContractualRoomPassengerDetail::create([
                    'room_detail_id' => $roomDetail->id,
                    'passenger_title' => $passenger['title'] ?? null,
                    'passenger_full_name' => $passenger['full_name'] ?? null,
                    'phone_code' => $passenger['phone_code'] ?? null,
                    'passenger_contact_number' => $passenger['contact_number'] ?? null,
                    'passenger_email_address' => $passenger['email_address'] ?? null,
                    'nationality_id' => $nationalityId,
                ]);
            }
        }
    }
     public function prepareBookingData(Request $request, $contractualBooking, $is_updated = 0)
    {
        $roomDetails = ContractualRoomDetail::with('passengers')->where('booking_id', $contractualBooking->id)->get();
        $extra_bed_for_child = '';
        foreach ($roomDetails as $room) {
            if ($room->extra_bed_for_child == 1) {
                $extra_bed_for_child = 'Yes';
                break;
            }
        }
        $currency = ($request->input('currency') ?? $contractualBooking->currency);

        // Add nationality and prepare traveller data
        $roomDetails->transform(function ($room) {
            $room->nationality = optional(Country::find($room->nationality_id))->name ?? 'None';

            // Sort passengers by ID for consistent indexing
            $passengers = $room->passengers->sortBy('id')->values();

            // Decode child ages JSON
            $childAges = json_decode($room->child_ages ?? '[]', true);
            $childIndex = 0;

            foreach ($passengers as $passenger) {
                if ($passenger->passenger_title === 'Child') {
                    $passenger->traveller_type = isset($childAges[$childIndex])
                        ? 'Child (' . $childAges[$childIndex] . 'yo)'
                        : 'Child';
                    $childIndex++;
                } elseif (in_array($passenger->passenger_title, ['Mr.', 'Ms.', 'Mrs.'])) {
                    $passenger->traveller_type = 'Adult';
                } else {
                    $passenger->traveller_type = $passenger->passenger_title ?? 'N/A';
                }

                $passenger->nationality = optional(Country::find($passenger->nationality_id))->name ?? 'None';
            }

            $room->passengers = $passengers;
            return $room;
        });

        // Group by room number
        $groupedByRoom = $roomDetails->groupBy('room_no');
        // dd($groupedByRoom);
        $user = User::where('id', $contractualBooking->user_id)->first() ?? auth()->user();

        // Retrieve admin and agent logos from the Company table
        $adminLogo = public_path('/img/logo.png');

        // First get the agent_code of the current user
        $agentCode = $user->agent_code;

        // Then find the actual agent user who owns this agent_code
        $agent = User::where('type', 'agent')->where('agent_code', $agentCode)->first();

        $agentLogo = null;

        $timezone_abbreviation = 'UTC'; // fallback
        $timezones = json_decode($contractualBooking->countryRelation->timezones);
        if (is_array($timezones) && isset($timezones[0]->abbreviation)) {
            $timezone_abbreviation = $timezones[0]->abbreviation;
        }
        if ($agent) {
            // Now get the logo from the company table using the agent's ID
            $agentLogo = Company::where('user_id', $agent->id)->value('logo');
        }

        $agentLogoUrl = asset(str_replace('/public/', '', $agentLogo));
        $agentLogo = $agentLogo ? public_path(str_replace('/public/', '', $agentLogo)) : $adminLogo;
        if (file_exists($agentLogo) && is_readable($agentLogo)) {
            $imageData = base64_encode(file_get_contents($agentLogo));
        } else {
            // If the agent logo is not found, use the admin logo
            $imageData = base64_encode(file_get_contents($adminLogo));
        }
        // $imageData = base64_encode(file_get_contents($agentLogo));
        $agentLogo = 'data:image/png;base64,' . $imageData;

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

        $basePrice = $currency . ' ' . $contractualBooking->total_cost;
        $rateId = $contractualBooking->genting_rate_id;
        $contractualRate = ContractualHotelRate::where('id', $rateId)->first();
        $remarks = $contractualRate->remarks ?? '';

        $phone = $agent->phone ?? $user->phone;
        $phoneCode = $agent->phone_code ?? $user->phone_code;
        $hirerEmail = $agent->email ?? $user->email;
        $hirerName = ($agent ?? $user)->first_name . ' ' . ($agent ?? $user)->last_name;
        $booking_status = Booking::with(['user.company'])->where('id', $contractualBooking->booking_id)->first();
        $hirerPhone = $phoneCode . $phone;
        // Assign to/from locations based on these values
       

        $bookingDate = convertToUserTimeZone($contractualBooking->created_at, 'F j, Y H:i T') ?? convertToUserTimeZone($request->input('booking_date'), 'F j, Y H:i T');
        $pickupAddress = $contractualBooking->pickup_address ?? $request->input('pickup_address');
        $dropoffAddress = $contractualBooking->dropoff_address ?? $request->input('dropoff_address');
        $child = $request->input('number_of_children') ?? $contractualBooking->number_of_children;
        $adults = $request->input('number_of_adults') ?? $contractualBooking->number_of_adults;
        $infants = $request->input('number_of_infants') ?? $contractualBooking->number_of_infants;
        // $roomDetails->transform(function ($room, $key) {
        //     $country = Country::find($room->nationality_id);
        //     $room->nationality = $country ? $country->name : 'None';
        //     return $room;
        // });
        // // Group by room number
        // $groupedByRoom = $roomDetails->groupBy('room_no');

        // $weekday = $bookingDate->format('l'); // e.g., 'Saturday'
        // $bookingDateStr = $bookingDate->toDateString(); // e.g., '2025-05-01'

        $checkIn = Carbon::parse(trim($contractualBooking->check_in ?? $request->input('check_in')));
        $checkOut = Carbon::parse(trim($contractualBooking->check_out ?? $request->input('check_out')));
        $numNights = $checkIn->diffInDays($checkOut); // Number of nights
        $numRooms = count($groupedByRoom);
        
        $adultPrice =  0;
        $childPrice = 0;

        // $add_breakfast = 0;
        $numberOfChildren = (int) ($request->additional_children ?? $contractualBooking->additional_children);
        $numberOfAdults = (int) ($request->additional_adults ?? $contractualBooking->additional_adults);

        $additional_adult_price = $numberOfAdults * $adultPrice * $numNights;
        $additional_child_price = $numberOfChildren * $childPrice * $numNights;

       

        // $netRate = $baseRate + $totalSurcharge;
        $voucher = VoucherRedemption::where('booking_id', $booking_status->id)->first();
        $discount = 0;
        $discountedPrice = 0;
        if ($voucher) {
            $discount = $voucher->discount_amount;
            $discountedPrice = str_replace(',', '', $contractualBooking->total_cost) - $discount;
        }
        // $entitlements = json_decode($contractualBooking->contractualRate->entitlements, true);
        
        $paymentMode = $contractualBooking->booking->payment_type;
        return [
            'id' => $contractualBooking->id,
            'booking_id' => $booking_status->booking_unique_id,
            'country_name'=>$contractualBooking->countryRelation->name,
            'city_name'=>$contractualBooking->cityRelation->name,
          'passenger_full_name' => $request->input('passenger_full_name')
    ?? optional(optional($contractualBooking->roomDetails->first())->passengers->first())->full_name,

'passenger_email_address' => $request->input('passenger_email_address')
    ?? optional(optional($contractualBooking->roomDetails->first())->passengers->first())->email,

            'booking_date' => $bookingDate,
            'tour_date' => '',
            'pick_time' => '',
            'base_price' => $basePrice,
            'hours' => $contractualBooking->hours ?? $request->input('hours'),
            'hotel_name' => $request->input('hotel_name') ?? $contractualBooking->hotel_name,
            'seating_capacity' => $request->input('seating_capacity') ?? $contractualBooking->room_capacity,
            'pickup_address' => $pickupAddress,
            'dropoff_address' => $dropoffAddress,
            'agent_voucher_no' => auth()->id(),
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
            'deadlineDate' => Carbon::parse($booking_status->deadline_date)->format('F j, Y H:i') . ' ' . $timezone_abbreviation,
            'infants' => $infants,
            'child' => $child,
            'adults' => $adults,
            'roomDetails' => $groupedByRoom,
            'room_capacity' => $request->input('room_capacity') ?? $contractualBooking->room_capacity,
            'agentLogoUrl' => $agentLogoUrl,
            'child_ages' => json_decode($request->input('child_ages')) ?? json_decode($contractualBooking->child_ages),
            'is_updated' => $is_updated,
            'created_by_admin' => $contractualBooking->created_by_admin ?? false,
            'updated_at' => convertToUserTimeZone($request->input('updated_at'), 'F j, Y H:i T') ?? convertToUserTimeZone($contractualBooking->updated_at, 'F j, Y H:i T'),
            'type' => $contractualBooking->type ?? $request->input('type'),
            'package' => $contractualBooking->package ?? $request->input('package'),
            'check_out' => $contractualBooking->check_out ?? $request->input('check_out'),
            'check_in' => $contractualBooking->check_in ?? $request->input('check_in'),
            'room_type' => $contractualBooking->room_type ?? $request->input('room_type'),
            'number_of_rooms' => $contractualBooking->number_of_rooms ?? $request->input('number_of_rooms'),
            'entitlements' => $contractualBooking->contractualRate->entitlements,
            'extra_bed_for_child' => $extra_bed_for_child,
            'reservation_id' => $contractualBooking->reservation_id ?? null,
            'confirmation_id' => $contractualBooking->confirmation_id ?? null,
            'additional_adults' => $contractualBooking->additional_adults,
            'additional_children' => $contractualBooking->additional_children,
            'additional_adult_price' => $additional_adult_price,
            'additional_child_price' => $additional_child_price,
            'netRate' => $booking_status->net_rate,
            'netCurrency' => $booking_status->net_rate_currency,
            'bookingCreatedBy' => $booking_status->user->agent_name,
            'paymentMode' => $paymentMode,
            'voucher_code' => $request->voucher_code,
            'voucher' => $voucher ? $voucher->voucher : null,
            'discount' => $discount,
            'currency' => $currency,
            'discountedPrice' => $discountedPrice,
        ];
    }
    public function sendVoucherEmail($request, $contractualBooking, $is_updated = 0)
    {
        $bookingData = $this->prepareBookingData($request, $contractualBooking, $is_updated);
        $user = User::where('id', $contractualBooking->user_id)->first();
        $passengerName = $user->first_name . ' ' . $user->last_name;
        $directoryPath = public_path("bookings/contractual_hotel");
        // Create the directory if it doesn't exist
        if (!file_exists($directoryPath)) {
            mkdir($directoryPath, 0755, true); // Create the directory with permissions
        }
        // Create a unique name for the PDF using bookingId and current timestamp
        $timestamp = now()->format('Ymd'); // e.g., 20241023_153015
        if ($contractualBooking->booking->booking_status === 'vouchered') {
            $pdfFilePathInvoice = "{$directoryPath}/hotel_invoice_paid_{$timestamp}_{$contractualBooking->booking_id}.pdf";
        } else {
            $pdfFilePathInvoice = "{$directoryPath}/hotel_booking_invoice_{$timestamp}_{$contractualBooking->booking_id}.pdf";
        }
        $pdfFilePathVoucher = "{$directoryPath}/hotel_booking_voucher_{$timestamp}_{$contractualBooking->booking_id}.pdf";
        $pdfFilePathAdminVoucher = "{$directoryPath}/hotel_booking_admin_voucher_{$timestamp}_{$contractualBooking->booking_id}.pdf";
        // booking_voucher
        $pdf = Pdf::loadView('email.contractualhotel.hotel_booking_invoice', $bookingData);
        $pdf->save($pdfFilePathInvoice);

        $pdfVoucher = Pdf::loadView('email.contractualhotel.hotel_booking_voucher', $bookingData);
        $pdfVoucher->save($pdfFilePathVoucher);

        $pdfAdmin = Pdf::loadView('email.contractualhotel.hotel_booking_admin_voucher', $bookingData);
        $pdfAdmin->save($pdfFilePathAdminVoucher);

        $email = $user->email;

        // $isCreatedByAdmin = $tourBooking->created_by_admin; // to track creation by admin

        // Mail::to($email)->send(new TransferBookingInvoiceMail($bookingData, $pdfFilePathInvoice, $passengerName));
        $mailInstance = new HotelBookingInvoiceMail($bookingData, $pdfFilePathInvoice, $passengerName);
        SendEmailJob::dispatch($email, $mailInstance);

        // Mail::to($email)->send(new BookingMail($bookingData, $pdfFilePathVoucher, $passengerName));
        $mailInstance = new HotelBookingVoucherMail($bookingData, $pdfFilePathVoucher, $passengerName);
        SendEmailJob::dispatch($email, $mailInstance);
        if (auth()->user()->type !== 'admin') {
            // Mail::to(['tours@grtravel.net', 'info@grtravel.net'])->send(new BookingMailToAdmin($bookingData, $pdfFilePathAdminVoucher, $passengerName));
            $emailAdmin1 = config('mail.notify_contractual_hotel');
            $emailAdmin2 = config('mail.notify_info');
            $emailAdmin3 = config('mail.notify_account');
            $mailInstance = new HotelVoucherToAdminMail($bookingData, $pdfFilePathAdminVoucher, $passengerName);
            SendEmailJob::dispatch($emailAdmin1, $mailInstance);
            $mailInstance = new HotelVoucherToAdminMail($bookingData, $pdfFilePathAdminVoucher, $passengerName);
            SendEmailJob::dispatch($emailAdmin2, $mailInstance);
            $mailInstance = new HotelVoucherToAdminMail($bookingData, $pdfFilePathAdminVoucher, $passengerName);
            SendEmailJob::dispatch($emailAdmin3, $mailInstance);
        }
    }




}

