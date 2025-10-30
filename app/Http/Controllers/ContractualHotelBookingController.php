<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\ContractualHotelService;
use App\Models\Location;
use App\Models\User;
use App\Models\CurrencyRate;
use App\Models\ContractualHotel;
use App\Models\ContractualHotelRate;
use App\Models\ContractualHotelBooking;
use App\Models\Country;
use App\Models\AgentPricingAdjustment;
use App\Models\Booking;
use App\Models\ContractualRoomDetail;
use App\Models\ContractualRoomPassengerDetail;
use App\Models\HotelSurcharge;
use App\Jobs\SendEmailJob;
use App\Tables\ContractualBookingTableConfigurator;
use App\Mail\ContractualHotel\HotelBookingApproved;
use Illuminate\Support\Facades\Validator;
use ProtoneMedia\Splade\Facades\Toast;
use Illuminate\Support\Facades\Redirect;
use Carbon\Carbon;
use ProtoneMedia\Splade\SpladeTable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
class ContractualHotelBookingController extends Controller
{
     protected $contractualHotelService;

    public function __construct(ContractualHotelService $contractualHotelService)
    {
        $this->contractualHotelService = $contractualHotelService;
    }
     public function index()
    {
        // Get the first GentingHotel instance and eager load the related location, country, and city
      $hotel = ContractualHotel::with(['cityRelation:id,name,country_code', 'countryRelation:id,name'])
        ->limit(3)
        ->get();
    

        return view('web.contractualhotel.hotel_dashboard', [ 'hotels' => $hotel]);
    }
    public function fetchlist(Request $request)
    {
        // echo "<pre>";print_r($request->all());die();
        try {
            $validated = $request->validate([
                'travel_location' => 'nullable|string',
                'travel_date' => 'nullable|date',
                'destination' => 'nullable|string',
                'pick_time' => 'nullable|date_format:H:i:s',
                'vehicle_seating_capacity' => 'nullable|integer|min:1',
                'vehicle_luggage_capacity' => 'nullable|integer|min:1',
            ]);

            $parameters = $this->contractualHotelService->extractRequestParameters($request);
            $query = $this->contractualHotelService->buildQuery($parameters);
            // echo "<pre>";print_r($query->toArray());die();
            $results = $this->contractualHotelService->applyFiltersAndPaginate($query, $parameters);
            $adjustedRates = $this->contractualHotelService->adjustHotel($results, $parameters);
            // echo "<pre>";print_r($adjustedRates);die();
            return $this->prepareResponse($request, $adjustedRates, $parameters);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()]);
        }
    }

    private function prepareResponse(Request $request, $adjustedRates, array $parameters)
{
    $nextPageUrl = $adjustedRates->nextPageUrl();

    if ($request->ajax()) {
        return response()->json([
            'html' => view('web.contractualhotel.listhotel', [
                'showHotels' => $adjustedRates,
                'currency' => $parameters['currency'],
                'parameters' => $parameters,
            ])->render(),
            'next_page' => $nextPageUrl,
        ]);
    }

    $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
    $canCreate = auth()->user()->type == 'staff' &&
        !in_array(auth()->user()->agent_code, $adminCodes) &&
        Gate::allows('create booking');

    return view('web.contractualhotel.search', [
        'showHotels' => $adjustedRates,
        'booking_date' => $parameters['check_in_out'],
        'filters' => $this->contractualHotelService->getFilters($parameters, $adjustedRates),
        'next_page' => $nextPageUrl,
        'parameters' => $parameters,
        'locationArray' => ['location' => ['name' => $parameters['location']['name'] ?? '']],
        'canCreate' => $canCreate,
    ]);
}
    public function hotelview(Request $request, $id, $pick_date, $currency, $rate, $room_details)
{
    $roomDetails = json_decode($room_details, true);

    $user = auth()->user();
    $canCreate = $user->type !== 'staff'
        || in_array($user->agent_code, User::where('type', 'admin')->pluck('agent_code')->toArray())
        || Gate::allows('create booking');

    $contractualHotel = ContractualHotel::with('cityRelation','countryRelation')->find($id);
    if (!$contractualHotel) {
        return redirect()->back()->with('error', 'Contractual Hotel not found');
    }

    [$checkInStr, $checkOutStr, $nights] = $this->parseDates($pick_date);
    $checkIn = \Carbon\Carbon::parse($checkInStr);
    $checkOut = \Carbon\Carbon::parse($checkOutStr);

    // Capacity condition closure
    $capacityCondition = function ($query) use ($roomDetails) {
        foreach ($roomDetails as $room) {
            $query->where('room_capacity', '>=', $room['adult_capacity'] + $room['child_capacity']);
        }
    };

    // Fetch first rates and filter by effective/expiry dates
    $firstRates = ContractualHotelRate::where('hotel_id', $id)
        ->where($capacityCondition)
        ->get()
        ->filter(fn($rate) => $checkIn->greaterThanOrEqualTo(Carbon::parse($rate->effective_date))
            && $checkOut->lessThanOrEqualTo(Carbon::parse($rate->expiry_date))
        );

    // Fetch next rates related to firstRates and filter
    $nextRates = ContractualHotelRate::whereHas('contractualHotel', fn($q) =>
            $q->whereIn('id', $firstRates->pluck('contractualHotel.id')))
        ->where($capacityCondition)
        ->get()
        ->filter(fn($rate) => $checkIn->greaterThanOrEqualTo(Carbon::parse($rate->effective_date))
            && $checkOut->lessThanOrEqualTo(Carbon::parse($rate->expiry_date))
        )
        ->map(fn($rate) => tap($rate, fn($r) => $r->adjusted_rate = ($r->price / 2) * $nights));

    // Merge rates
    $rates = $firstRates->merge($nextRates)
        ->filter()
        ->values()
        ->transform(function ($item) use ($currency, $roomDetails, $checkIn, $checkOut) {
            if (!$item) return null;

            // Total rooms
            $totalRooms = array_sum(array_map(fn($r) => $r['quantity'] ?? 1, $roomDetails));
            if ($totalRooms <= 0) $totalRooms = count($roomDetails) ?: 1;

            // Total price calculation
            $start = $checkIn->copy();
            $end = $checkOut->copy();
            $totalPrice = 0.0;
            for ($d = $start; $d->lt($end); $d->addDay()) {
                $dow = (int)$d->format('w');
                $totalPrice += in_array($dow, [0,6])
                    ? (float)($item->weekend_price ?? $item->price ?? 0)
                    : (float)($item->weekdays_price ?? $item->weekday_price ?? $item->price ?? 0);
            }

            // Surcharge calculation
            $surcharge = $this->calculateSurcharge(
                $item->hotel_id,
                $checkIn,
                $checkOut,
                (float)($item->weekdays_price ?? $item->weekday_price ?? 0),
                (float)($item->weekend_price ?? 0)
            );

            $item->total_price = ($totalPrice + $surcharge) * $totalRooms;

            // Currency conversion
            $item->converted_price = app('App\Services\ContractualHotelService')
                ->applyCurrencyConversion($item->total_price, $item->currency, $currency);

            return $item;
        })
        ->filter();

    $adjustedRates = $this->contractualHotelService->adjustHotel($rates, ['currency' => $currency], 'hotelView');

    // Time slot validation
    $timeSlots = json_decode($contractualHotel->time_slots, true);
    $selectedSlot = $request->input('time_slots');
    if ($selectedSlot && !in_array($selectedSlot, $timeSlots)) {
        return redirect()->back()->with('error', 'Invalid time slot selected');
    }

    // Description extraction
    [$paragraph, $listItems] = $this->extractDescription($contractualHotel->description);

    return view('contractualHotel.hotelView', [
        'data' => $adjustedRates,
        'contractualHotels' => $contractualHotel,
        'check_in_out' => $pick_date,
        'room_details' => $roomDetails,
        'currency' => $currency,
        'property_amenities' => $contractualHotel->property_amenities,
        'room_features' => $contractualHotel->room_features,
        'room_types' => $contractualHotel->room_types,
        'paragraph' => $paragraph,
        'listItems' => $listItems,
        'booking_date' => $pick_date,
        'booking_slot' => $selectedSlot,
        'countries' => Country::pluck('name', 'id'),
        'canCreate' => $canCreate,
    ]);
}


       private function parseDates($range)
    {
        [$start, $end] = explode(' to ', $range);
        $checkIn = Carbon::parse($start);
        $checkOut = Carbon::parse($end);
        $nights = $checkIn->diffInDays($checkOut);

        return [$checkIn, $checkOut, $nights];
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

    private function extractDescription($description)
    {
        $lines = explode("\n", $description);
        $listItems = array_filter($lines, fn($line) => str_starts_with(trim($line), '*'));
        $paragraph = implode(' ', array_filter($lines, fn($line) => !str_starts_with(trim($line), '*') && !empty(trim($line))));
        return [$paragraph, $listItems];
    }
    public function hotelBookingSubmission(Request $request, $id, $check_in_out, $currency, $room_details)
    {

        $roomDetails = json_decode($room_details, true);
        $user = auth()->user();

        // Check permissions for staff
        $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        if (
            $user->type == 'staff' &&
            !in_array($user->agent_code, $adminCodes) &&
            Gate::denies('create booking')
        ) {
            abort(403, 'This action is unauthorized.');
        }

        // Fetch the contractual hotel rate
        $data = ContractualHotelRate::where('id', $id)
            ->where(function ($query) use ($roomDetails) {
                foreach ($roomDetails as $room) {
                    $totalCapacity = (int) $room['adult_capacity'] + (int) $room['child_capacity'];
                    $query->where('room_capacity', '>=', $totalCapacity);
                }
            })
            ->first();

        if (!$data) {
            return redirect()->back()->with('error', 'Hotel rate not found');
        }

        // Parse check-in and check-out dates
        $dates = explode(' to ', urldecode($check_in_out));
        $checkIn = Carbon::parse($dates[0]);
        $checkOut = Carbon::parse($dates[1]);
        $numNights = $checkIn->diffInDays($checkOut);

        $totalRooms = count($roomDetails);
        $totalPrice = 0;

        // Calculate base price (weekday/weekend)
        $currentDate = $checkIn->copy();
        while ($currentDate->lt($checkOut)) {
            $dayOfWeek = $currentDate->format('w'); // 0=Sun, 6=Sat
            if ($dayOfWeek == 6 || $dayOfWeek == 0) {
                $totalPrice += $data->weekend_price ?? 0;
            } else {
                $totalPrice += $data->weekdays_price ?? 0;
            }
            $currentDate->addDay();
        }
        // echo "<pre>";print_r($totalPrice);die();
        // Calculate surcharges
        $surcharge = $this->calculateSurcharge(
            $data->hotel_id,
            $checkIn,
            $checkOut,
            $totalPrice / $numNights // pass average nightly price
        );

        // Total for all rooms
        $totalPrice = ($totalPrice + $surcharge) * $totalRooms;

        // Apply currency conversion
        $convertedPrice = app('App\Services\ContractualHotelService')
            ->applyCurrencyConversion($totalPrice, $data->currency, $currency);
            // echo "<pre>";print_r($convertedPrice);die();

        // Apply agent adjustment
        $agentId = User::where('agent_code', $user->agent_code)
            ->where('type', 'agent')
            ->value('id');

        $adjustmentRate = AgentPricingAdjustment::getCurrentAdjustmentRate($agentId);
        if ($adjustmentRate && $adjustmentRate->isNotEmpty()) {
            $hotelRates = $adjustmentRate->filter(function ($rate) {
                return $rate->transaction_type === 'contractual_hotel';
            });

            foreach ($hotelRates as $hotelRate) {
                $convertedPrice = app('App\Services\ContractualHotelService')
                    ->applyAdjustment($convertedPrice, $hotelRate);
            }
        }

        // Hotel details
        $hotel = ContractualHotel::with('cityRelation','countryRelation')->where('id', $data->hotel_id)->first();
        if (!$hotel) {
            return redirect()->back()->with('error', 'Hotel not found');
        }
        // echo "<pre>";print_r($hotel);die();
        return view('web.contractualhotel.hotel_booking', [
            'data' => $data,
            'contractualHotels' => $hotel,
            'check_in' => $checkIn->format('Y-m-d'),
            'check_out' => $checkOut->format('Y-m-d'),
            'roomDetails' => $roomDetails,
            'currency' => $currency,
            'price' => $convertedPrice,
            'countries' => Country::all(['id', 'name'])->pluck('name', 'id'),
        ]);
    }
    public function store(Request $request)
    {
        try {
            // Get rules and messages from the method
            $validationData = $this->contractualHotelService->hotelFormValidationArray($request);
            $rules = $validationData['rules'];
            $messages = $validationData['messages'];

            // echo "<pre>";print_r($messages);die();
            $validator = Validator::make($request->all(), $rules, $messages);
            $user = auth()->user();
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $hotelBooking = $this->contractualHotelService->storeBooking($request);
            $hotelBooking = json_decode($hotelBooking->getContent(), true);
            // echo "<pre>";print_r($hotelBooking);die();
            if(isset($hotelBooking['errors'])) {

                $errorMsg = collect($hotelBooking['errors'])->flatten()->first(); // get first error message

                Toast::title($errorMsg)
                ->danger()
                ->rightBottom()
                ->autoDismiss(5);
                // echo "<pre>";print_r($errorMsg);die();
                return response()->json([
                    'success' => false,
                    'error' => $errorMsg,
                ], 400); // Or 422 depending on type

            }
            session()->flash('success', 'Booking request submitted!');
            $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
            if ($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('list booking')) {
                return response()->json([
                    'success' => true,
                    'message' => 'Booking successfully created!',
                    'redirect_url' => route('contractual_hotel.dashboard'),
                ]);
            }
            return response()->json([
                'success' => true,
                'message' => 'Booking request submitted!',
                'redirect_url' => route('contractualBookings.index'),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'An error occurred: ' . $e], 500);
        }
    }
    public function showBookings(Request $request)
    {
        // echo "<pre>";print_r($request->all());die();
        $user = auth()->user();
        // Retrieve search inputs
        $search = $request->input('search'); // General search (if needed)
        $referenceNo = $request->input('user_id');
        $bookingId = $request->input('booking_unique_id');
        $reservationType = $request->input('booking_status');
        $gentingName = $request->input('hotel_name');
        $check_in = $request->input('check_in');
        $check_out = $request->input('check_out');
        $location = $request->input('location');
        $type = $request->input('type');
        $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        if ($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('list booking')) {
            abort(403, 'This action is unauthorized.');
        }

        // Query the database with search and filters
        $bookings = ContractualHotelBooking::query()
            ->leftJoin('bookings', 'contractual_hotel_bookings.booking_id', '=', 'bookings.id') // Join with the bookings table
            ->leftJoin('contractual_hotel_rates', 'contractual_hotel_bookings.rate_id', '=', 'contractual_hotel_rates.id') // Join gentings using genting_rate_id
            ->leftJoin('contractual_hotels', 'contractual_hotel_rates.hotel_id', '=', 'contractual_hotels.id') // Join tour_destinations table
            ->leftJoin('countries as country', 'contractual_hotel_bookings.country_id', '=', 'country.id') // Join locations for tours
            ->leftJoin('cities as city', 'contractual_hotel_bookings.city_id', '=', 'city.id') // Join locations for tours
            ->leftJoin('users as agent', 'contractual_hotel_bookings.user_id', '=', 'agent.id') // Join booking table with users table
            ->leftJoin('voucher_redemptions', 'contractual_hotel_bookings.booking_id', '=', 'voucher_redemptions.booking_id') // Join voucher redemptions
            ->select(
                'contractual_hotel_bookings.*',
                'bookings.*', // Select columns from the bookings table
                'country.name as country_name', // Location name for tours
                'city.name as city_name', // Location name for tours
                'agent.agent_code', // agent_code from users table
                'voucher_redemptions.discount_amount as voucher_discount', // Select voucher discount
            )
            // Filter based on user type
            ->when($user->type === 'agent', function ($query) use ($user) {
                // Include bookings for the agent and their staff
                $staffIds = User::where('type', 'staff')->where('agent_code', $user->agent_code)->pluck('id');
                return $query->where(function ($subQuery) use ($user, $staffIds) {
                    $subQuery->where('contractual_hotel_bookings.user_id', $user->id)
                        ->orWhereIn('contractual_hotel_bookings.user_id', $staffIds);
                });
            })
            ->when($user->type === 'staff' && !in_array($user->agent_code, $adminCodes), function ($query) use ($user) {
                return $query->where('contractual_hotel_bookings.user_id', $user->id);
            })
            ->when($referenceNo, function ($query, $referenceNo) {
                return $query->where('contractual_hotel_bookings.user_id', $referenceNo);
            })
            ->when($bookingId, function ($query, $bookingId) {
                return $query->where('bookings.booking_unique_id', 'like', '%' . $bookingId);
            })
            ->when($reservationType, function ($query, $reservationType) {
                if ($reservationType !== '') {
                    return $query->where('bookings.booking_status', $reservationType); // '1' or '0'
                }
            })
            ->when($gentingName, function ($query, $gentingName) {
                return $query->where('contractual_hotel_bookings.hotel_name', 'like', "%{$gentingName}%");
            })
                       ->when($check_out, function ($query, $check_out) {
                // Ensure time is in the correct format, and compare with `$check_out` field
                return $query->whereDate('contractual_hotel_bookings.check_out', '=', $check_out);
            })
            ->when($check_in, function ($query, $check_in) {
                // Ensure time is in the correct format, and compare with `check$check_in` field
                return $query->whereDate('contractual_hotel_bookings.check_in', '=', $check_in);
            })
            ->when($location, function ($query, $location) {
                return $query->where('location.name', 'like', "%{$location}%");
            })
            ->when($type, function ($query, $type) {
                return $query->where('contractual_hotel_bookings.type', 'like', "%{$type}%");
            })
            ->with(['booking', 'booking.user'])
            ->orderBy('contractual_hotel_bookings.id', 'desc')
            ->orderBy('bookings.booking_date', 'desc')
            ->paginate(10)
            ->appends($request->all()); // Retain query inputs in pagination links

        // Total bookings count
        $totalBookings = ContractualHotelBooking::count();
        $offline_payment = route('contractualHotelOfflineTransaction');
        $limit = auth()->user()->getEffectiveCreditLimit();
        // echo "<pre>";print_r($bookings);die();

        return view('web.contractualhotel.hotelBookingList', compact('limit','bookings', 'totalBookings', 'offline_payment'));
    }
    public function bookings(){

        return view('contractualBooking.index', [
            'contractual_booking' => new ContractualBookingTableConfigurator(),

        ]);
    
    }
    public function viewDetails($id)
    {
        $booking = ContractualHotelBooking::with(['countryRelation', 'users.company','contractualRate'])->where('booking_id', $id)->firstOrFail();
        $roomDetails = ContractualRoomDetail::where('booking_id', $booking->id)->with('passengers')->get();
        $passengerQuery = ContractualRoomPassengerDetail::query()
            ->select(
                'contractual_room_passenger_details.*',
                'contractual_room_details.room_no',
                'contractual_room_details.extra_bed_for_child'
            )
            ->join('contractual_room_details', 'contractual_room_passenger_details.room_detail_id', '=', 'contractual_room_details.id')
            ->where('contractual_room_details.booking_id', $booking->id)
            ->with('nationality', 'roomDetail');

        $roomDetails = SpladeTable::for($passengerQuery)
            ->column('room_no', 'Room No')
            ->column('passenger_full_name', 'Passenger Name')
            ->column('passenger_email_address', 'Passenger Email')
            ->column('passenger_contact_number', 'Passenger Contact No.')
            ->column(key: 'extra_bed_for_child', label: 'Child Bed', as: fn($c, $m) => $m->extra_bed_for_child === 0 ? 'NO' : 'YES')
            ->column('nationality.name', 'Nationality')
            ->column('traveller_type', 'Traveller Type') // Uses accessor
            ->column(
                key: 'child_ages',
                label: 'Child Age',
                as: function ($value, $model) {
                    if ($model->passenger_title !== 'Child' || !$model->roomDetail) {
                        return '-';
                    }

                    $childAges = json_decode($model->roomDetail->child_ages ?? '[]', true);
                    $children = $model->roomDetail->passengers
                        ->where('passenger_title', 'Child')
                        ->sortBy('id')
                        ->values();

                    $index = $children->search(fn($child) => $child->id === $model->id);
                    return $childAges[$index] ?? '-';
                }
            )
            ->column('action', 'Action')
            ->paginate(10);
        // Load all countries for select input
        $countries = Country::pluck('name', 'id');
        $currency = $booking->currency;

        $bookingStatus = Booking::where('id', $id)->first();

        $user = User::where('id', $booking->user_id)->first();
        $createdBy = (($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: 'Admin';

        // Default
        $companyName = '';
        $credit_limit_currency = '';
        $credit_limit = '';

        if ($user->type === 'staff') {
            $mainAgent = User::where('agent_code', $user->agent_code)
                ->whereHas('company')
                ->with('company') // eager load company to avoid later null checks
                ->first();

            if ($mainAgent) {
                $credit_limit_currency = $mainAgent->credit_limit_currency;
                $credit_limit = number_format($mainAgent->credit_limit, 2);
                $companyName = optional($mainAgent->company)->agent_name;
            } else {
                $credit_limit_currency = $user->credit_limit_currency;
                $credit_limit = number_format($user->credit_limit, 2);
            }
        } else {
            // Fallback for agent/admin or any other types
            $credit_limit_currency = $user->credit_limit_currency;
            $credit_limit = number_format($user->credit_limit, 2);
            $companyName = optional($user->company)->agent_name;
        }


        $location = Location::where('id', $booking->location_id)->value('name');

        $nationality = Country::where('id', $booking->nationality_id)->value('name');
        $hotels = ContractualHotel::pluck('hotel_name', 'id');
        $bookedHotel = null;
        if ($booking && $booking->hotel_id) {
            $bookedHotel = $booking->hotel_id;
        }
        // echo "<pre>";print_r($bookedHotel);die();
        // $bookedHotel = optional($booking->contractualRate->gentingHotel)->id ?? null;
        $targetDate = Carbon::createFromFormat('Y-m-d H:i:s', $bookingStatus->service_date);
        // Get the current date and time
        $currentDate = Carbon::now();
        // Calculate the difference in days
        $remainingDays = $currentDate->diffInDays($targetDate, false); // 'false' to allow negative values if targetDate is in the past
        // $user = auth()->user();
        $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        $can_edit = !($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('update booking'));
        $can_delete = !($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('delete booking'));
        $fullRefund = route('fullRefund', ['service_id' => $bookingStatus->id, 'service_type' => $bookingStatus->booking_type]);
        $offline_payment = route('gentingOfflineTransaction');

        $deadlineDate = null;
        if ($bookingStatus->deadline_date) {
            list($date, $time) = explode(" ", $bookingStatus->deadline_date);
            $deadlineDate = [
                'date' => $date,
                'time' => Carbon::parse($time)->format('H:i')
            ];
        }
        // Return the view with booking details
        return view('contractualBooking.details', compact(
            'booking',
            'nationality',
            'countries',
            'hotels',
            'bookedHotel',
            'location',
            'currency',
            'createdBy',
            'bookingStatus',
            'remainingDays',
            'can_edit',
            'can_delete',
            'roomDetails',
            'fullRefund',
            'companyName',
            'offline_payment',
            'credit_limit',
            'credit_limit_currency',
            'deadlineDate',
        ));
    }
    public function approve($id, Request $request)
    {
        // ✅ Validate request data first
        $validator = Validator::make($request->all(), [
            'date' => ['required', 'date', 'after_or_equal:today'],
            'time' => ['required', 'date_format:H:i'],
        ]);
        if ($validator->fails()) {
            Toast::title($validator->errors()->first())
                ->danger()
                ->rightBottom()
                ->autoDismiss(5);
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        // Fetch the booking or fail if not found
        $contractualBooking = ContractualHotelBooking::with('countryRelation')->where('booking_id', $id)->firstOrFail();
        $booking = Booking::where('id', $id)
            ->whereIn('booking_type', ['contractual_hotel'])
            ->first();
        // echo "<pre>";print_r($booking);die();
        // Determine if the currently authenticated user is an admin
        $isCreatedByAdmin = $contractualBooking->created_by_admin; // Assuming this field exists to track creation by admin

        // Approve the booking
        $contractualBooking->approved = true;
        $contractualBooking->sent_approval = false;
        $booking->booking_status = 'confirmed';
        $booking->save();
        $contractualBooking->save();

        // Check if the booking was not created by admin and if the email has not been sent yet
        if (!$isCreatedByAdmin) {

            $agentInfo = User::where('id', $contractualBooking->user_id)->first(['email', 'first_name']);
            $agentEmail = $agentInfo->email;
            $agentName = $agentInfo->first_name; // Get the agent's name

            $mailInstance = new HotelBookingApproved($contractualBooking, $agentName, $booking->booking_unique_id);
            SendEmailJob::dispatch($agentEmail, $mailInstance);
            $dropOffName = null;
            $pickUpName = null;
            $is_updated = null;
            // Mark the email as sent
            $contractualBooking->email_sent = true;
            $contractualBooking->save();
            $deadlineDate = $request->input('date'); // e.g., 2025-04-14
            $deadlineTime = $request->input('time'); // e.g., 13:00

            $booking->deadline_date = Carbon::createFromFormat('Y-m-d H:i', $deadlineDate . ' ' . $deadlineTime)->format('Y-m-d H:i:s');
            $booking->save();
            app(ContractualHotelService::class)->sendVoucherEmail(request(), $contractualBooking, $is_updated);

            Toast::title('Booking Approved successfully')
                ->success()
                ->rightBottom()
                ->autoDismiss(5);
            return redirect()->back()->with('success', 'Booking Approved successfully.');
        }
    }
    public function toggleEditForm($id)
    {
        $booking = ContractualHotelBooking::findOrFail($id);

        // Check if the form is currently displayed
        if (session()->has('contractualEditForm')) {
            // Toggle the session value
            session()->forget('contractualEditForm');
        } else {
            // Set the session value to true, showing the edit form
            session(['contractualEditForm' => true]);
        }

        // Redirect back to the same page to show the form
        return redirect()->back();
    }
   public function listRooms($id, $booking_id)
    {
        // Find booking
        $booking = ContractualHotelBooking::find($booking_id);
        if (!$booking) {
            return response()->json(['message' => 'Booking not found'], 404);
        }

        $roomDetails = ContractualRoomDetail::where('booking_id', $booking->id)->get();
        $currency = $booking->currency;
        $numberOfRooms = $booking->number_of_rooms;
        $checkIn = \Carbon\Carbon::parse($booking->check_in);
        $checkOut = \Carbon\Carbon::parse($booking->check_out);

        // Fetch hotel
        $contractualHotel = ContractualHotel::find($id);
        if (!$contractualHotel) {
            return response()->json(['message' => 'Contractual Hotel not found'], 404);
        }

        // Fetch rates valid for given dates
        $rates = ContractualHotelRate::where('hotel_id', $id)
            ->get()
            ->filter(fn($rate) =>
                $checkIn->greaterThanOrEqualTo(Carbon::parse($rate->effective_date)) &&
                $checkOut->lessThanOrEqualTo(Carbon::parse($rate->expiry_date))
            );

        if ($rates->isEmpty()) {
            return response()->json(['message' => 'No valid rates found'], 404);
        }

        // Calculate for each rate
        $rooms = $rates->map(function ($rate) use ($currency, $checkIn, $checkOut, $numberOfRooms, $roomDetails, $booking) {
            $start = $checkIn->copy();
            $end = $checkOut->copy();
            $totalPrice = 0.0;

            // Loop through nights
            for ($d = $start; $d->lt($end); $d->addDay()) {
                $dow = (int)$d->format('w'); // 0=Sun,6=Sat
                $totalPrice += in_array($dow, [0,6])
                    ? (float)($rate->weekend_price ?? $rate->price ?? 0)
                    : (float)($rate->weekdays_price ?? $rate->weekday_price ?? $rate->price ?? 0);
            }

            // Surcharge
            $surcharge = $this->calculateSurcharge(
                $rate->hotel_id,
                $checkIn,
                $checkOut,
                (float)($rate->weekdays_price ?? $rate->price ?? 0),
                (float)($rate->weekend_price ?? 0)
            );
            // echo "<pre>";print_r($surcharge);die();

            // Multiply with number of rooms
            $rate->total_price = ($totalPrice + $surcharge) * $numberOfRooms;

            // Convert currency
            $convertedPrice = app('App\Services\ContractualHotelService')
                ->applyCurrencyConversion($rate->total_price, $rate->currency, $currency);

            // Apply agent adjustment
            $adjustmentRates = AgentPricingAdjustment::getCurrentAdjustmentRate($booking->user_id);
            foreach ($adjustmentRates as $adjustmentRate) {
                if ($adjustmentRate->transaction_type === 'contractual_hotel') {
                    $convertedPrice = app('App\Services\ContractualHotelService')
                        ->applyAdjustment($convertedPrice, $adjustmentRate);
                }
            }

            return [
                'id' => $rate->id,
                'room_type' => $rate->room_type,
                'price' => $convertedPrice,
                'currency' => $currency,
                'capacity' => $rate->room_capacity,
                'formattedLabel' => $rate->room_type . ' - ' . $currency . ' ' . number_format($convertedPrice, 2),
            ];
        });

        return response()->json($rooms);
    }
    public function getPrice($id, $booking_id)
    {
        // Fetch the contractual room rate
        $rate = ContractualHotelRate::find($id);

        if (!$rate) {
            return response()->json(['message' => 'Room not found'], 404);
        }

        // Fetch the booking data
        $booking = ContractualHotelBooking::find($booking_id);

        if (!$booking) {
            return response()->json(['message' => 'Booking not found'], 404);
        }

        // Get currency and number of rooms
        $currency = $booking->currency;
        $numberOfRooms = $booking->number_of_rooms;
        $checkIn = Carbon::parse($booking->check_in);
        $checkOut = Carbon::parse($booking->check_out);

        // Calculate number of nights
        $numNights = $checkIn->diffInDays($checkOut);

        $totalPrice = 0.0;

        // Loop through each night of the stay
        $currentDate = clone $checkIn;
        while ($currentDate->lt($checkOut)) {
            $dow = (int)$currentDate->format('w'); // 0 = Sunday, 6 = Saturday
            $totalPrice += in_array($dow, [0, 6]) 
                ? (float)($rate->weekend_price ?? $rate->price ?? 0)
                : (float)($rate->weekdays_price ?? $rate->weekday_price ?? $rate->price ?? 0);

            $currentDate->addDay();
        }

        // Calculate surcharge (using your existing helper)
        $surcharge = $this->calculateSurcharge(
            $rate->hotel_id,
            $checkIn,
            $checkOut,
            (float)($rate->weekdays_price ?? $rate->price ?? 0),
            (float)($rate->weekend_price ?? 0)
        );

        // Final base price
        $totalPrice = ($totalPrice + $surcharge) * $numberOfRooms;

        // Currency conversion
        $convertedPrice = app('App\Services\ContractualHotelService')
            ->applyCurrencyConversion($totalPrice, $rate->currency, $currency);

        // Apply agent pricing adjustment
        $adjustmentRates = AgentPricingAdjustment::getCurrentAdjustmentRate($booking->user_id);
        foreach ($adjustmentRates as $adjustmentRate) {
            if ($adjustmentRate->transaction_type === 'contractual_hotel') {
                $convertedPrice = app('App\Services\ContractualHotelService')
                    ->applyAdjustment($convertedPrice, $adjustmentRate);
            }
        }

        // Return formatted price
        return response()->json([
            'id' => $rate->id,
            'price' => round($convertedPrice, 2),
            'currency' => $currency
        ]);
    }
    public function infoEdit(Request $request, $id)
    {
        try {
            // Validate the request
            $validated = $request->validate([
                'total_cost' => 'required|numeric|min:0', // Ensure it's required, numeric, and not negative
                'rooms' => 'required|exists:contractual_hotel_rates,id' // Ensure room ID exists in the genting_rates table
            ]);
            // Fetch the booking record
            $hotelBooking = ContractualHotelBooking::with('countryRelation')->findOrFail($id); // Throws 404 if not found
            // echo "<pre>";print_r($hotelBooking);die();
            $booking = Booking::where('booking_type_id', $id)->first();
            // Fetch the selected GentingRate based on the provided room ID
            $rates = ContractualHotelRate::with('contractualHotel')->find($request->rooms);
            // echo "<pre>";print_r($rates);die();

            if (!$rates) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected room rate not found.'
                ], 404);
            }

            $hotelBookingUpdated = $hotelBooking->update([
                'hotel_name' => $rates->contractualHotel->hotel_name,
                'room_type' => $rates->room_type,
                'total_cost' => $validated['total_cost'],
                'rate_id' => $rates->id,
                'country_id'=>$rates->contractualHotel->country_id,
                'city_id'=>$rates->contractualHotel->city_id
            ]);

            $bookingUpdated = $booking->update([
                'amount' => $validated['total_cost'],
            ]);

            if ($hotelBookingUpdated && $bookingUpdated) {
                Log::info('Contractual booking and booking amount updated successfully.', [
                    'booking_id' => $booking->id,
                    'contractual_booking_id' => $hotelBooking->id,
                    'amount' => $validated['total_cost'],
                ]);
            } else {
                Log::error('Update failed: One or both updates did not return true.', [
                    'booking_id' => $booking->id,
                    'contractual_booking_id' => $hotelBooking->id,
                ]);
            }

            // Send email notification
            $is_updated = 1;
            $this->contractualHotelService->sendVoucherEmail($request, $hotelBooking, $is_updated);

            Toast::title('Booking details updated successfully.')
                ->success()
                ->rightBottom()
                ->autoDismiss(5);
            session()->forget('gentingEditForm');
            return redirect()->back()->with('success', 'Booking details updated successfully.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Handle validation errors properly
            return back()->withErrors($e->errors());
        } catch (\Exception $e) {
            Toast::title('Something went wrong! Please try again later.')
                ->message($e->getMessage()) // Display the actual error message
                ->danger()
                ->rightBottom()
                ->autoDismiss(5);

            return back()->withErrors(['error' => 'Something went wrong! Please try again later.']);
        }
    }
    public function roomEdit(Request $request, $booking_id, $room_no)
    {
        try {
            // Fetch the booking record
            $passengerDetails = ContractualRoomPassengerDetail::where('id', $booking_id)->where('room_detail_id', $room_no)->first();
            // Check if the booking exists
            if (!$passengerDetails) {
                return response()->json([
                    'success' => false,
                    'message' => 'Booking not found.'
                ], 404);
            }

            // Validate the incoming data
            $request->validate([
                'passenger_full_name' => 'required|string|max:255',
                'passenger_email_address' => 'nullable|email|max:255',
                'passenger_contact_number' => 'nullable',
            ]);

            // Update the booking with validated data
            $passengerDetails->update($request->only([
                'passenger_full_name',
                'passenger_email_address',
                'passenger_contact_number',
                'nationality_id',
                'phone_code',
            ]));
            $is_updated = 1;
            $this->contractualHotelService->sendVoucherEmail($request, $passengerDetails->roomDetail->booking, $is_updated);
            Toast::title('Booking details updated successfully.')
                ->success()
                ->rightBottom()
                ->autoDismiss(5);
            return redirect()->back()->with('success', 'Booking details updated successfully.');
        } catch (\Exception $e) {
            Toast::title('Something went wrong! Please try again later.')
                ->message($e->getMessage()) // Display the actual error message
                ->danger()
                ->rightBottom()
                ->autoDismiss(5);
            return back()->withErrors(['error' => 'Something went wrong! Please try again later.']);
        }
    }
    public function toggleEditReservationForm($id)
    {
        $booking = ContractualHotelBooking::findOrFail($id);

        // Check if the form is currently displayed
        if (session()->has('editReservationForm')) {
            // Toggle the session value
            session()->forget('editReservationForm');
        } else {
            // Set the session value to true, showing the edit form
            session(['editReservationForm' => true]);
        }

        // Redirect back to the same page to show the form
        return redirect()->back();
    }
    public function updateReservation($booking)
    {
        $booking = ContractualHotelBooking::with('countryRelation')->find($booking);
        $request = request();
        $request->validate($this->reservationFormValidation($request));

        $booking->update([
            'confirmation_id' => $request->input('confirmation_id'),
            'reservation_id' => $request->input('reservation_id')
        ]);

        $is_updated = 1;

        $this->contractualHotelService->sendVoucherEmail($request, $booking, $is_updated);

        // Success message
        Toast::title('Reservation Information Updated')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);

        session()->forget('editReservationForm');
        return redirect()->back()->with('success', 'Reservation information updated successfully.');
    }
    public function reservationFormValidation(Request $request): array
    {
        return [
            "confirmation_id" => ['required',],
            "reservation_id" => ['required',]
        ];
    }


    public function unapprove(Request $request, $id)
    {
        $gentingBooking = GentingBooking::where('booking_id', $id)->firstOrFail();
        $booking = Booking::where('id', $id)
            ->whereIn('booking_type', ['genting_hotel'])
            ->first();
        $isCreatedByAdmin = $gentingBooking->created_by_admin;
        $gentingBooking->approved = false;
        $gentingBooking->sent_approval = false;
        $booking->booking_status = 'rejected';
        $booking->save();
        $gentingBooking->save();

        // Check if the booking was not created by admin and if the email has not been sent yet
        if (!$isCreatedByAdmin && !$gentingBooking->email_sent) {

            $agentInfo = User::where('id', $gentingBooking->user_id)->first(['email', 'first_name']);
            $agentEmail = $agentInfo->email;
            $agentName = $agentInfo->first_name; // Get the agent's name
            $mailInstance = new GentingBookingUnapproved($gentingBooking, $agentName, $booking->booking_unique_id, $request->reject_note);
            SendEmailJob::dispatch($agentEmail, $mailInstance);
            // Mark the email as sent
            $gentingBooking->email_sent = true;
            $gentingBooking->save();
            Toast::title('Booking Rejected')
                ->success()
                ->rightBottom()
                ->autoDismiss(5);
            return redirect()->back()->with('success', 'Booking Rejected.');
        }

        return redirect()->back()->with('success', 'Booking Rejected.');
    }

    






}
