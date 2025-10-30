<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmailJob;
use App\Mail\BookingCancel;
use App\Mail\Genting\GentingBookingApproved;
use App\Models\AgentPricingAdjustment;
use App\Models\Booking;
use App\Models\CancellationPolicies;
use App\Models\Country;
use App\Models\CurrencyRate;
use App\Models\GentingAddBreakFast;
use App\Models\GentingBooking;
use App\Models\GentingHotel;
use App\Models\GentingPackage;
use App\Models\GentingRate;
use App\Models\GentingRoomDetail;
use App\Models\GentingRoomPassengerDetail;
use App\Models\GentingSurcharge;
use App\Models\Location;
use App\Models\Surcharge;
use App\Models\User;
use App\Models\VoucherRedemption;
use App\Services\GentingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;
use ProtoneMedia\Splade\Facades\Toast;

class AgentGentingController extends Controller
{

    protected $gentingService;

    public function __construct(GentingService $gentingService)
    {
        $this->gentingService = $gentingService;
    }

    public function index()
    {
        // Get the first GentingHotel instance and eager load the related location, country, and city
        $hotel = GentingHotel::with(['location.country', 'location.city'])->first();
        // Check if hotel and related data exist
        $location = $hotel->location ?? null;
        $country = $location->country ?? null;
        $city = $location->city ?? null;

        // Prepare the data to pass to the view
        $locationArray = [
            'location' => [
                'name' => $location->name ?? '',
                'country' => $country->name ?? '',
                'city' => $city->name ?? '',
                'latitude' => $city->latitude ?? '',
                'longitude' => $city->longitude ?? ''
            ]
        ];

        // Fetch the hotels and check if they exist
        $hotels = GentingHotel::limit(3)->get();

        // If no hotels are found, you can pass an empty collection or handle as needed
        $hotels = $hotels->isEmpty() ? collect() : $hotels;

        return view('web.genting.genting_dashboard', ['locationArray' => $locationArray, 'hotels' => $hotels]);
    }

    public function store(Request $request)
    {
        try {
            // Get rules and messages from the method
            $validationData = $this->gentingService->gentingFormValidationArray($request);
            $rules = $validationData['rules'];
            $messages = $validationData['messages'];

            $validator = Validator::make($request->all(), $rules, $messages);
            $user = auth()->user();
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $tourBooking = $this->gentingService->storeBooking($request);
            $tourBooking = json_decode($tourBooking->getContent(), true);
            if (!$tourBooking['success']) {

                Toast::title($e->getMessage())
                    ->danger()
                    ->rightBottom()
                    ->autoDismiss(5);

                if ($request->ajax()) {
                    return $tourBooking;
                }
                return Redirect::back()->withErrors(['error' => $e->getMessage()]);
            }
            session()->flash('success', 'Booking request submitted!');
            $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
            if ($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('list booking')) {
                return response()->json([
                    'success' => true,
                    'message' => 'Booking successfully created!',
                    'redirect_url' => route('genting.dashboard'),
                ]);
            }
            return response()->json([
                'success' => true,
                'message' => 'Booking request submitted!',
                'redirect_url' => route('gentingBookings.index'),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'An error occurred: ' . $e], 500);
        }
    }

    public function edit()
    {
    }

    public function updatePassenger(Request $request, $id, $room_no)
    {
        try {
            // Fetch the booking record
            // $booking = GentingRoomDetail::where('booking_id', $id)->where('room_no', $room_no)->first();
            $passengerDetails = GentingRoomPassengerDetail::where('id', $id)->where('room_detail_id', $room_no)->first();
            // Check if the booking exists
            if (!$passengerDetails) {
                return response()->json([
                    'success' => false,
                    'message' => 'Booking not found.'
                ], 404);
            }

            // Call the service to update passenger information
            $booking = $this->gentingService->updatePassenger($request, $passengerDetails);

            // If the request is AJAX, return a JSON response
            if ($request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Booking details updated successfully.',
                    'booking' => $booking
                ]);
            }

            // For non-AJAX (normal form submission), redirect back with success message
            return redirect()->route('gentingBookings.details', ['id' => $booking->booking_id])
                ->with('success', 'Booking details updated successfully.');
        } catch (\Exception $e) {
            // Handle any exceptions and return a JSON error response for AJAX requests
            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage()
                ], 500);
            }

            // For non-AJAX, return the usual error handling (could be a redirect or error message)
            return back()->withErrors(['error' => 'Something went wrong! Please try again later.']);
        }
    }

    public function updateResAndConf(Request $request, $booking_id)
    {
        try {
            // Fetch the booking record
            $booking = GentingBooking::with('location.country')->where('booking_id', $booking_id)->first();

            // Check if the booking exists
            if (!$booking) {
                return response()->json([
                    'success' => false,
                    'message' => 'Booking not found.'
                ], 404);
            }

            // Call the service to update passenger information
            $booking = $this->gentingService->updatePassenger($request, $booking);

            // If the request is AJAX, return a JSON response
            if ($request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Booking details updated successfully.',
                    'booking' => $booking
                ]);
            }

            // For non-AJAX (normal form submission), redirect back with success message
            return redirect()->route('gentingBookings.details', ['id' => $booking->id])
                ->with('success', 'Reservation & Confirmation ID updated successfully.');
        } catch (\Exception $e) {
            // Handle any exceptions and return a JSON error response for AJAX requests
            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage()
                ], 500);
            }

            // For non-AJAX, return the usual error handling (could be a redirect or error message)
            return back()->withErrors(['error' => 'Something went wrong! Please try again later.']);
        }
    }


    public function fetchlist(Request $request)
    {
        // echo "<pre>";print_r($request->all());die();

        // Validate the input fields
        $validated = $request->validate([
            'travel_location' => 'nullable',
            'travel_date' => 'nullable|date',
            'destination' => 'nullable',
            'pick_time' => 'nullable|date_format:H:i:s',
            'vehicle_seating_capacity' => 'nullable|integer|min:1',
            'vehicle_luggage_capacity' => 'nullable|integer|min:1',
        ]);

        try {
            $parameters = $this->gentingService->extractRequestParameters($request);
                
            $query = $this->gentingService->buildQuery($parameters);
            $results = $this->gentingService->applyFiltersAndPaginate($query, $parameters);
            $adjustedRates = $this->gentingService->adjustGenting($results, $parameters);
            return $this->prepareResponse($request, $adjustedRates, $parameters);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()]);
        }
    }

    private function prepareResponse(Request $request, $adjustedRates, array $parameters)
    {
        $nextPageUrl = $adjustedRates->nextPageUrl();
        // Group tours by 'tour_name' after fetching paginated results
        if ($request->ajax()) {
            return response()->json([
                'html' => view('web.genting.listGenting', [
                    'showHotels' => $adjustedRates,
                    // 'cancellationPolicy' => CancellationPolicies::where('active', 1)->where('type', 'genting')->first(),
                    'currency' => $parameters['currency'],
                    'parameters' => $parameters,
                ])->render(),
                'next_page' => $nextPageUrl,
            ]);
        }

        // Get the first GentingHotel instance and eager load the related location, country, and city
        $hotel = GentingHotel::with(['location.country', 'location.city'])->first();

        // Check if hotel and related data exist
        $location = $hotel->location ?? null;
        $country = $location->country ?? null;
        $city = $location->city ?? null;

        // Prepare the data to pass to the view
        $locationArray = [
            'location' => [
                'name' => $location->name ?? '',
                'country' => $country->name ?? '',
                'city' => $city->name ?? '',
                'latitude' => $city->latitude ?? '',
                'longitude' => $city->longitude ?? ''
            ]
        ];
        $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        $canCreate = auth()->user() == 'staff' && !in_array(auth()->user()->agent_code, $adminCodes) && Gate::allows('create booking');
        $package = GentingPackage::first();
        return view('web.genting.search', [
            // 'cancellationPolicy' => CancellationPolicies::where('active', 1)->where('type', 'genting')->first(),
            'showHotels' => $adjustedRates,
            'booking_date' => $parameters['check_in_out'],
            'filters' => $this->gentingService->getFilters($parameters, $adjustedRates),
            'next_page' => $nextPageUrl,
            'parameters' => $parameters,
            'package' => $package,
            'locationArray' => $locationArray,
            'canCreate' => $canCreate,
        ]);
    }

    // public function gentingView(Request $request, $id, $pick_date, $currency, $rate, $room_details)
    // {
    //     $roomDetails = json_decode($room_details, true);
    //     $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
    //     $canCreate = auth()->user()->type !== 'staff' ||
    //         in_array(auth()->user()->agent_code, $adminCodes) ||
    //         Gate::allows('create booking');

    //     $gentingHotels = GentingHotel::where('id', $id)->first();
    //     // Check if tour rate data exists
    //     if (!$gentingHotels) {
    //         return redirect()->back()->with('error', 'Genting Hotel not found');
    //     }

    //     $firstFiveHotels = GentingRate::where('genting_hotel_id', $gentingHotels->id)
    //         ->whereHas('gentingPackage', function ($query) {
    //             $query->where('nights', '<', 2); // Only allow packages with less than 2 nights
    //         })
    //         ->where(function ($query) use ($roomDetails) {
    //             foreach ($roomDetails as $room) {
    //                 $totalCapacity = (int) $room['adult_capacity'] + (int) $room['child_capacity'];

    //                 $query->where('room_capacity', '>=', $totalCapacity);
    //             }
    //         })
    //         ->get();

    //     $dates = explode(' to ', $pick_date);
    //     $checkIn = Carbon::parse($dates[0]);
    //     $checkOut = Carbon::parse($dates[1]);
    //     $selectedNights = $checkIn->diffInDays($checkOut); // User-selected number of nights
    //     // Fetch same hotels but with packages that have more than 1 night
    //     $nextFiveHotels = GentingRate::whereHas('gentingHotel', function ($query) use ($firstFiveHotels) {
    //         $query->whereIn('id', $firstFiveHotels->pluck('gentingHotel.id')); // Ensures only same hotels are fetched
    //     })
    //         ->whereHas('gentingPackage', function ($query) {
    //             $query->where('nights', '>', 1);
    //         })
    //         ->where(function ($query) use ($roomDetails) {
    //             foreach ($roomDetails as $room) {
    //                 $totalCapacity = (int) $room['adult_capacity'] + (int) $room['child_capacity'];
    //                 $query->where('room_capacity', '>=', $totalCapacity);
    //             }
    //         })
    //         ->get()
    //         ->map(function ($rate) use ($selectedNights) {
    //             // Adjust rates for more than 1-night packages
    //             $rate->adjusted_rate = ($rate->price / 2) * $selectedNights;
    //             return $rate;
    //         });

    //     // Merge both collections
    //     $data = $firstFiveHotels->merge($nextFiveHotels);

    //     // Decode the JSON time_slots from the gentingHotels table
    //     $timeSlots = json_decode($gentingHotels->time_slots, true);

    //     // Get the selected time slot
    //     $selectedTimeSlot = $request->input('time_slots');

    //     // Check if the selected time slot is valid
    //     if ($selectedTimeSlot && !in_array($selectedTimeSlot, $timeSlots)) {
    //         return redirect()->back()->with('error', 'Invalid time slot selected');
    //     }

    //     $totalRooms = count($roomDetails);
    //     // dd($data);
    //     $data->transform(function ($item) use ($currency, $totalRooms, $pick_date) {
    //         $dates = explode(' to ', $pick_date);
    //         $checkIn = Carbon::parse($dates[0]);
    //         $checkOut = Carbon::parse($dates[1]);

    //         // Calculate number of nights
    //         $numNights = $checkIn->diffInDays($checkOut);

    //         if ($item) {
    //             $totalSurcharge = 0;
    //             $appliedWeekendSurcharge = false;

    //             // Fetch surcharge details from genting_surcharges table
    //             $surchargeData = GentingSurcharge::where('genting_hotel_id', $item->genting_hotel_id)
    //                 ->value('surcharges');

    //             if ($surchargeData) {
    //                 $surcharges = json_decode($surchargeData, true) ?? [];
    //                 $weekendDays = [];

    //                 foreach ($surcharges as $surcharge) {
    //                     if ($surcharge['surcharge_type'] === 'weekend') {
    //                         $weekendDays[] = ucfirst(strtolower($surcharge['surcharge_details']['weekend']));
    //                     }
    //                 }

    //                 $currentDate = clone $checkIn;
    //                 while ($currentDate->lt($checkOut)) {
    //                     foreach ($surcharges as $surcharge) {
    //                         if ($surcharge['surcharge_type'] === 'weekend' && in_array($currentDate->format('l'), $weekendDays) && !$appliedWeekendSurcharge) {
    //                             $totalSurcharge += $surcharge['surcharge_details']['amount'];
    //                             $appliedWeekendSurcharge = true;
    //                         }

    //                         if ($surcharge['surcharge_type'] === 'fixed_date' && $currentDate->format('Y-m-d') === $surcharge['surcharge_details']['fixed_date']) {
    //                             $totalSurcharge += $surcharge['surcharge_details']['amount'];
    //                         }

    //                         if ($surcharge['surcharge_type'] === 'date_range') {
    //                             $startDate = Carbon::parse($surcharge['surcharge_details']['start_date']);
    //                             $endDate = Carbon::parse($surcharge['surcharge_details']['end_date']);

    //                             if ($currentDate->between($startDate, $endDate)) {
    //                                 $totalSurcharge += $surcharge['surcharge_details']['amount'];
    //                             }
    //                         }
    //                     }
    //                     $currentDate->addDay();
    //                 }
    //             }

    //             // Apply rate calculation
    //             $price = isset($item->adjusted_rate) ? $item->adjusted_rate : $item->price * $numNights;
    //             $item->converted_price = app('App\Services\GentingService')
    //                 ->applyCurrencyConversion(($price + $totalSurcharge) * $totalRooms, $item->currency, $currency);
    //         }

    //         return $item;
    //     });

    //     // Filter out items where 'converted_price' is not set
    //     $data = $data->filter(function ($item) {
    //         return isset($item->converted_price); // Only keep items where the 'converted_price' is set
    //     });



    //     // Split the highlights string by hyphen "\n" and trim extra spaces from each item
    //     $facilities = json_decode($gentingHotels->facilities, true);
    //     $description = $gentingHotels->descriptions;

    //     // Separate lines into an array
    //     $lines = explode("\n", $description);

    //     // Filter lines that start with '*'
    //     $listItems = array_filter($lines, function ($line) {
    //         return str_starts_with(trim($line), '*');
    //     });

    //     // Get the paragraph content (lines that do not start with '*')
    //     $paragraph = implode(' ', array_filter($lines, function ($line) {
    //         return !str_starts_with(trim($line), '*') && !empty(trim($line));
    //     }));

    //     return view(
    //         'web.genting.genting_view',
    //         [
    //             'data' => $data,
    //             'gentingHotels' => $gentingHotels,
    //             'check_in_out' => $pick_date,
    //             'room_details' => $roomDetails,
    //             'currency' => $currency,
    //             'facilities' => $facilities,
    //             'paragraph' => $paragraph,
    //             'listItems' => $listItems,
    //             'booking_date' => $pick_date,
    //             'booking_slot' => $selectedTimeSlot,
    //             'countries' => Country::all(['id', 'name'])->pluck('name', 'id'),
    //             'canCreate' => $canCreate,
    //         ]
    //     );
    // }

    public function gentingView(Request $request, $id, $pick_date, $currency, $rate, $room_details)
    {

        $roomDetails = json_decode($room_details, true);
        $user = auth()->user();
        $canCreate = $user->type !== 'staff' || in_array($user->agent_code, User::where('type', 'admin')->pluck('agent_code')->toArray()) || Gate::allows('create booking');

        $gentingHotel = GentingHotel::find($id);
        if (!$gentingHotel) {
            return redirect()->back()->with('error', 'Genting Hotel not found');
        }

        [$checkIn, $checkOut, $nights] = $this->parseDates($pick_date);

        $capacityCondition = function ($query) use ($roomDetails) {
            foreach ($roomDetails as $room) {
                $query->where('room_capacity', '>=', $room['adult_capacity'] + $room['child_capacity']);
            }
        };

        $firstRates = GentingRate::where('genting_hotel_id', $id)
            ->whereHas('gentingPackage', fn($q) => $q->where('nights', '<', 2))
            ->where($capacityCondition)
            ->get();

        $nextRates = GentingRate::whereHas('gentingHotel', fn($q) => $q->whereIn('id', $firstRates->pluck('gentingHotel.id')))
            ->whereHas('gentingPackage', fn($q) => $q->where('nights', '>', 1))
            ->where($capacityCondition)
            ->get()
            // ->map(fn($rate) => $rate->adjusted_rate = ($rate->price / 2) * $nights ? $rate : $rate);
            ->map(function ($rate) use ($nights) {
                $rate->adjusted_rate = ($rate->price / 2) * $nights;
                return $rate;
            });

        $rates = $firstRates->merge($nextRates)->transform(function ($item) use ($currency, $roomDetails, $checkIn, $checkOut, $nights) {
            if (!$item)
                return null;

            $totalRooms = count($roomDetails);
            $price = $item->adjusted_rate ?? $item->price * $nights;
            $surcharge = $this->calculateSurcharge($item->genting_hotel_id, $checkIn, $checkOut);
            $item->total_price = ($price + $surcharge) * $totalRooms;
            $item->converted_price = app('App\Services\GentingService')->applyCurrencyConversion(($price + $surcharge) * $totalRooms, $item->currency, $currency);

            return $item;
        })->filter();
        $parameters = [
            'currency' => $currency
        ];
        $adjustedRates = $this->gentingService->adjustGenting($rates, $parameters, 'gentingView');

        $timeSlots = json_decode($gentingHotel->time_slots, true);
        $selectedSlot = $request->input('time_slots');
        if ($selectedSlot && !in_array($selectedSlot, $timeSlots)) {
            return redirect()->back()->with('error', 'Invalid time slot selected');
        }

        $facilities = json_decode($gentingHotel->facilities, true);
        [$paragraph, $listItems] = $this->extractDescription($gentingHotel->descriptions);

        return view('web.genting.genting_view', [
            'data' => $rates,
            'gentingHotels' => $gentingHotel,
            'check_in_out' => $pick_date,
            'room_details' => $roomDetails,
            'currency' => $currency,
            'facilities' => $facilities,
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

    private function calculateSurcharge($hotelId, $checkIn, $checkOut)
    {
        $surchargeData = GentingSurcharge::where('genting_hotel_id', $hotelId)->value('surcharges');
        if (!$surchargeData)
            return 0;

        $surcharges = json_decode($surchargeData, true);
        $total = 0;
        $weekendDays = collect($surcharges)->filter(fn($s) => $s['surcharge_type'] === 'weekend')
            ->pluck('surcharge_details.weekend')->map(fn($d) => ucfirst(strtolower($d)))->toArray();

        for ($date = $checkIn->copy(); $date->lt($checkOut); $date->addDay()) {
            foreach ($surcharges as $s) {
                $type = $s['surcharge_type'];
                $details = $s['surcharge_details'];
                if ($type === 'weekend' && in_array($date->format('l'), $weekendDays)) {
                    $total += $details['amount'];
                    break; // apply once
                } elseif ($type === 'fixed_date' && $date->isSameDay(Carbon::parse($details['fixed_date']))) {
                    $total += $details['amount'];
                } elseif ($type === 'date_range') {
                    $start = Carbon::parse($details['start_date']);
                    $end = Carbon::parse($details['end_date']);
                    if ($date->between($start, $end)) {
                        $total += $details['amount'];
                    }
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

    public function gentingBookingSubmission(Request $request, $id, $check_in_out, $currency, $room_details)
    {
        $roomDetails = json_decode($room_details, true);
        // Check if child_ages is "N/A", and if so, treat it as an empty array
        $user = auth()->user();
        $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        if ($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('create booking')) {
            abort(403, 'This action is unauthorized.');
        }

        // Fetch the hotel with a 1-night package first
        $data = GentingRate::where('id', $id)
            ->whereHas('gentingPackage', function ($query) {
                $query->where('nights', '<', 2); // Only allow packages with less than 2 nights
            })
            ->where(function ($query) use ($roomDetails) {
                foreach ($roomDetails as $room) {
                    $totalCapacity = (int) $room['adult_capacity'] + (int) $room['child_capacity'];
                    $query->where('room_capacity', '>=', $totalCapacity);
                }
            })
            ->first();

        // If no 1-night package is found, try fetching a 2-night package instead
        if (!$data) {
            $data = GentingRate::where('id', $id)
                ->whereHas('gentingPackage', function ($query) {
                    $query->where('nights', '>', 1); // Fetch only 2-night packages
                })
                ->where(function ($query) use ($roomDetails) {
                    foreach ($roomDetails as $room) {
                        $totalCapacity = (int) $room['adult_capacity'] + (int) $room['child_capacity'];
                        $query->where('room_capacity', '>=', $totalCapacity);
                    }
                })
                ->first();
        }

        if ($data) {  // Check if data exists
            // Extract check-in and check-out dates
            $dates = explode(' to ', $check_in_out);
            $checkIn = Carbon::parse($dates[0]);
            $checkOut = Carbon::parse($dates[1]);

            // Calculate number of nights
            $numNights = $checkIn->diffInDays($checkOut);
            $totalRooms = count($roomDetails);
            $totalSurcharge = 0;

            // Fetch surcharge details from genting_surcharges table
            $surchargeData = GentingSurcharge::where('genting_hotel_id', $data->genting_hotel_id)
                ->value('surcharges');

            if ($surchargeData) {
                $surcharges = json_decode($surchargeData, true) ?? [];

                // Extract weekend days dynamically from the JSON
                $weekendDays = [];
                foreach ($surcharges as $surcharge) {
                    if ($surcharge['surcharge_type'] === 'weekend') {
                        $weekendDays[] = ucfirst(strtolower($surcharge['surcharge_details']['weekend']));
                    }
                }

                $dateRangeApplied = false; // Ensure date range surcharge is applied only once
                $appliedWeekendSurcharge = false;
                // Loop through each night of the stay (excluding checkout date)
                $currentDate = clone $checkIn;
                while ($currentDate->lt($checkOut)) {
                    foreach ($surcharges as $surcharge) {
                        if ($surcharge['surcharge_type'] === 'weekend' && in_array($currentDate->format('l'), $weekendDays) && !$appliedWeekendSurcharge) {
                            $totalSurcharge += $surcharge['surcharge_details']['amount'];
                            $appliedWeekendSurcharge = true;
                        }
                        if ($surcharge['surcharge_type'] === 'fixed_date' && $currentDate->format('Y-m-d') === $surcharge['surcharge_details']['fixed_date']) {
                            $totalSurcharge += $surcharge['surcharge_details']['amount'];
                        }
                        if ($surcharge['surcharge_type'] === 'date_range') {
                            $startDate = Carbon::parse($surcharge['surcharge_details']['start_date']);
                            $endDate = Carbon::parse($surcharge['surcharge_details']['end_date']);
                            if ($currentDate->between($startDate, $endDate)) {
                                $totalSurcharge += $surcharge['surcharge_details']['amount'];

                            }
                        }
                    }
                    $currentDate->addDay(); // Move to the next day
                }
            }
            if ($data->gentingPackage->nights > 1) {

                $genting_price = $this->gentingService->applyCurrencyConversion(
                    (($data->price / 2) * $numNights + $totalSurcharge) * $totalRooms,
                    $data->currency,
                    $currency,
                );
            } else {
                // Calculate total price with surcharges

                $genting_price = $this->gentingService->applyCurrencyConversion(
                    ($data->price * $numNights + $totalSurcharge) * $totalRooms,
                    $data->currency,
                    $currency
                );


            }
            $agentCode = auth()->user()->agent_code;

            $agentId = User::where('agent_code', $agentCode)
                ->where('type', 'agent')
                ->value('id');

            $adjustmentRate = AgentPricingAdjustment::getCurrentAdjustmentRate($agentId);
            if ($adjustmentRate && $adjustmentRate->isNotEmpty()) {

                $gentingRates = $adjustmentRate->filter(function ($rate) {
                    return $rate->transaction_type === 'genting_hotel';
                });
                foreach ($gentingRates as $gentingRate) {

                    $genting_price = $this->gentingService->applyAdjustment($genting_price, $gentingRate);
                }
            }
        } else {
            return redirect()->back()->with('error', 'Genting Hotel not found');
        }


        $gentingHotels = GentingHotel::where('id', $data->genting_hotel_id)->first();
        // Check if tour rate data exists
        if (!$gentingHotels) {
            return redirect()->back()->with('error', 'Genting Hotel not found');
        }
        $breakfast = GentingAddBreakFast::where('hotel_id', $gentingHotels->id)->first();
        $isBreakfast = false;
        $convertedAdultPrice = 0;
        $convertedChildPrice = 0;

        if ($breakfast) {
            $isBreakfast = true;

            $convertedAdultPrice = $this->gentingService->applyCurrencyConversion($breakfast->adult, $breakfast->currency, $currency) * $numNights;
            $convertedChildPrice = $this->gentingService->applyCurrencyConversion($breakfast->child, $breakfast->currency, $currency) * $numNights;
        }
        // Decode the JSON time_slots from the gentingHotels table
        $timeSlots = json_decode($gentingHotels->time_slots, true);  // Decode JSON into an array

        // Step 3: Get the selected time slot from the URL or request
        $selectedTimeSlot = $request->input('time_slots');

        // Check if the selected time slot is available in the time_slots array
        if ($selectedTimeSlot && !in_array($selectedTimeSlot, $timeSlots)) {
            return redirect()->back()->with('error', 'Invalid time slot selected');
        }

        // Split the highlights string by hyphen "\n" and trim extra spaces from each item
        $facilities = json_decode($gentingHotels->facilities, true);
        $description = $gentingHotels->descriptions;
        $entitlements = json_decode($data->entitlements, true);

        // Separate lines into an array
        $lines = explode("\n", $description);

        // Filter lines that start with '*'
        $listItems = array_filter($lines, function ($line) {
            return str_starts_with(trim($line), '*');
        });

        // Get the paragraph content (lines that do not start with '*')
        $paragraph = implode(' ', array_filter($lines, function ($line) {
            return !str_starts_with(trim($line), '*') && !empty(trim($line));
        }));
        $check_in_out = urldecode($check_in_out);  // Decode the URL-encoded string

        // Step 2: Use the correct separator to split the string
        $dates = explode(' to ', $check_in_out);  // Use 'to' as the separator without the '+'

        // Step 3: Access the check-in and check-out dates
        $check_in = $dates[0];
        $check_out = $dates[1];

        $childAges = collect($roomDetails)->pluck('child_ages')->flatten()->toArray(); // all child ages in order
        // dd($roomDetails);
        $totalChildren = count($childAges);
        $currencyRate = CurrencyRate::where('target_currency', $currency)->first();
        return view(
            'web.genting.genting_booking',
            [
                'data' => $data,
                'gentingHotels' => $gentingHotels,
                'check_in' => $check_in,
                'check_out' => $check_out,
                'roomDetails' => $roomDetails,
                'currency' => $currency,
                'price' => $genting_price,
                'facilities' => $facilities,
                'entitlements' => $entitlements,
                'paragraph' => $paragraph,
                'listItems' => $listItems,
                'booking_slot' => $selectedTimeSlot,
                'countries' => Country::all(['id', 'name'])->pluck('name', 'id'),
                'totalChildren' => $totalChildren,
                'isBreakfast' => $isBreakfast,
                'convertedAdultPrice' => $convertedAdultPrice,
                'convertedChildPrice' => $convertedChildPrice,
                'conversion_rate' => $currencyRate->rate ?? null,
            ]
        );
    }

    public function showBookings(Request $request)
    {
        $user = auth()->user();
        // Retrieve search inputs
        $search = $request->input('search'); // General search (if needed)
        $referenceNo = $request->input('user_id');
        $bookingId = $request->input('booking_unique_id');
        $reservationType = $request->input('booking_status');
        $gentingName = $request->input('hotel_name');
        $package = $request->input('package');
        $check_in = $request->input('check_in');
        $check_out = $request->input('check_out');
        $location = $request->input('location');
        $type = $request->input('type');
        $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        if ($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('list booking')) {
            abort(403, 'This action is unauthorized.');
        }

        // Query the database with search and filters
        $bookings = GentingBooking::query()
            ->leftJoin('bookings', 'genting_bookings.booking_id', '=', 'bookings.id') // Join with the bookings table
            ->leftJoin('genting_rates', 'genting_bookings.genting_rate_id', '=', 'genting_rates.id') // Join gentings using genting_rate_id
            ->leftJoin('genting_hotels', 'genting_rates.genting_hotel_id', '=', 'genting_hotels.id') // Join tour_destinations table
            ->leftJoin('locations as location', 'genting_bookings.location_id', '=', 'location.id') // Join locations for tours
            ->leftJoin('users as agent', 'genting_bookings.user_id', '=', 'agent.id') // Join booking table with users table
            ->leftJoin('voucher_redemptions', 'genting_bookings.booking_id', '=', 'voucher_redemptions.booking_id') // Join voucher redemptions
            ->select(
                'genting_bookings.*',
                'bookings.*', // Select columns from the bookings table
                'location.name as location_name', // Location name for tours
                'agent.agent_code', // agent_code from users table
                'voucher_redemptions.discount_amount as voucher_discount', // Select voucher discount
            )
            // Filter based on user type
            ->when($user->type === 'agent', function ($query) use ($user) {
                // Include bookings for the agent and their staff
                $staffIds = User::where('type', 'staff')->where('agent_code', $user->agent_code)->pluck('id');
                return $query->where(function ($subQuery) use ($user, $staffIds) {
                    $subQuery->where('genting_bookings.user_id', $user->id)
                        ->orWhereIn('genting_bookings.user_id', $staffIds);
                });
            })
            ->when($user->type === 'staff' && !in_array($user->agent_code, $adminCodes), function ($query) use ($user) {
                return $query->where('genting_bookings.user_id', $user->id);
            })
            ->when($referenceNo, function ($query, $referenceNo) {
                return $query->where('genting_bookings.user_id', $referenceNo);
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
                return $query->where('genting_bookings.hotel_name', 'like', "%{$gentingName}%");
            })
            ->when($package, function ($query, $package) {
                return $query->where('genting_bookings.package', 'like', "%{$package}%");
            })
            ->when($check_out, function ($query, $check_out) {
                // Ensure time is in the correct format, and compare with `$check_out` field
                return $query->whereDate('genting_bookings.check_out', '=', $check_out);
            })
            ->when($check_in, function ($query, $check_in) {
                // Ensure time is in the correct format, and compare with `check$check_in` field
                return $query->whereDate('genting_bookings.check_in', '=', $check_in);
            })
            ->when($location, function ($query, $location) {
                return $query->where('location.name', 'like', "%{$location}%");
            })
            ->when($type, function ($query, $type) {
                return $query->where('genting_bookings.type', 'like', "%{$type}%");
            })
            ->with(['booking', 'booking.user'])
            ->orderBy('genting_bookings.id', 'desc')
            ->orderBy('bookings.booking_date', 'desc')
            ->paginate(10)
            ->appends($request->all()); // Retain query inputs in pagination links

        // Total bookings count
        $totalBookings = GentingBooking::count();
        $offline_payment = route('gentingOfflineTransaction');
        $limit = auth()->user()->getEffectiveCreditLimit();

        return view('web.genting.gentingBookingList', compact('limit','bookings', 'totalBookings', 'offline_payment'));
    }

    public function viewDetails($id)
    {
        // Load cancellation booking policies dynamically
        $cancellation = CancellationPolicies::where('active', 1)->get();
        // Fetch the booking details by ID
        $booking = GentingBooking::where('booking_id', $id)->firstOrFail();
        $roomDetails = GentingRoomDetail::where('booking_id', $booking->id)->with('passengers')->get();
        $roomDetails->transform(function ($item) {
            $nationality = Country::where('id', $item->nationality_id)->value('name');
            $item->nationality = $nationality;
            return $item;
        });

        $currency = $booking->currency;

        $bookingStatus = Booking::where('id', $id)->first();

        $voucherRedemption = VoucherRedemption::where('booking_id', $id)->first();

        $user = User::where('id', $booking->user_id)->first();
        $createdBy = (($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: 'Admin';

        $location = Location::where('id', $booking->location_id)->value('name');

        $countries = Country::all(['id', 'name'])->pluck('name', 'id');

        $cancellationBooking = $cancellation->filter(function ($policy) use ($bookingStatus) {
            return $policy->type == $bookingStatus->booking_type && $bookingStatus->booking_type !== 'ticket';
        });

        $targetDate = Carbon::createFromFormat('Y-m-d H:i:s', $bookingStatus->service_date);
        // Get the current date and time
        $currentDate = Carbon::now();
        // Calculate the difference in days
        $remainingDays = $currentDate->diffInDays($targetDate, false); // 'false' to allow negative values if targetDate is in the past
        $user = auth()->user();
        $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        $can_edit = !($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('update booking'));
        $can_delete = !($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('delete booking'));
        $timezone_abbreviation = 'UTC'; // fallback

        try {
            $timezones = json_decode($booking->location->country->timezones);
            if (is_array($timezones) && isset($timezones[0]->abbreviation)) {
                $timezone_abbreviation = $timezones[0]->abbreviation;
            }
        } catch (\Throwable $e) {
            \Log::error('Timezone decode error', ['error' => $e->getMessage()]);
        }
        // Return the view with booking details
        return view('web.genting.gentingBooking_details', compact(
            'booking',
            'countries',
            'location',
            'currency',
            'createdBy',
            'bookingStatus',
            'cancellationBooking',
            'remainingDays',
            'can_edit',
            'can_delete',
            'roomDetails',
            'timezone_abbreviation',
            'voucherRedemption',
        ));
    }

    public function approve($id)
    {
        // Fetch the booking or fail if not found
        $gentingBooking = GentingBooking::with('location.country')->where('booking_id', $id)->firstOrFail();
        $booking = Booking::where('id', $id)->first();

        // Determine if the currently authenticated user is an admin
        $isCreatedByAdmin = $gentingBooking->created_by_admin; // Assuming this field exists to track creation by admin

        // Approve the booking
        $gentingBooking->approved = true;
        $gentingBooking->sent_approval = false;
        $booking->booking_status = 'confirmed';
        $booking->save();
        $gentingBooking->save();

        // Check if the booking was not created by admin and if the email has not been sent yet
        if (!$isCreatedByAdmin && !$gentingBooking->email_sent) {
            // if ($gentingBooking->type === 'ticket') {
            //     $agentInfo = User::where('id', $gentingBooking->user_id)->first(['email', 'first_name']);
            //     $agentEmail = $agentInfo->email;
            //     $agentName = $agentInfo->first_name; // Get the agent's name

            //     // Send the booking approval email to the agent
            //     // Mail::to($agentEmail)->send(new BookingApproved($fleetBooking, $agentName));
            //     $mailInstance = new GentingBookingApproved($gentingBooking, $agentName, $booking->booking_unique_id);
            //     SendEmailJob::dispatch($agentEmail, $mailInstance);
            //     $is_updated = null;
            //     app(GentingService::class)->sendVoucherEmail(request(), $gentingBooking, $is_updated);
            //     // Mark the email as sent
            //     $gentingBooking->email_sent = true;
            //     $gentingBooking->save();
            // } else {
            $agentInfo = User::where('id', $gentingBooking->user_id)->first(['email', 'first_name']);
            $agentEmail = $agentInfo->email;
            $agentName = $agentInfo->first_name; // Get the agent's name

            // Send the booking approval email to the agent
            // Mail::to($agentEmail)->send(new BookingApproved($fleetBooking, $agentName));
            $mailInstance = new GentingBookingApproved($gentingBooking, $agentName, $booking->booking_unique_id);
            SendEmailJob::dispatch($agentEmail, $mailInstance);
            $dropOffName = null;
            $pickUpName = null;
            $is_updated = null;
            app(GentingService::class)->sendVoucherEmail(request(), $gentingBooking, $is_updated);
            // Mark the email as sent
            $gentingBooking->email_sent = true;
            $gentingBooking->save();
            // }

            Toast::title('Booking Approved successfully')
                ->success()
                ->rightBottom()
                ->autoDismiss(5);
            return redirect()->back()->with('success', 'Booking Approved successfully.');
        }
    }

    public function unapprove($id)
    {
        $tourBooking = GentingBooking::findOrFail($id);

        $fromLocation = null;
        $toLocation = null;
        $isCancelByAdmin = $tourBooking->created_by_admin;

        $booking = Booking::where('booking_type_id', $tourBooking->id)->whereIn('booking_type', ['genting_hotel'])->first();
        if ($booking) {
            $booking->update(['booking_status' => 'cancelled']);
            $tourBooking->approved = false;
            $tourBooking->save();
        }

        if (!$isCancelByAdmin) {
            $agentInfo = User::find($tourBooking->user_id, ['email', 'first_name']);

            if ($agentInfo) {
                $agentEmail = $agentInfo->email;
                $agentName = $agentInfo->first_name;
                $amountRefunded = null;
                $location = null;

                $bookingDate = convertToUserTimeZone($booking->booking_date);
                $mailInstance = new BookingCancel($tourBooking, $agentName, $fromLocation, $toLocation, $bookingDate, $location, $booking->booking_type, $amountRefunded);
                SendEmailJob::dispatch($agentEmail, $mailInstance);
                $admin = new BookingCancel(
                    $tourBooking,
                    'Admin',
                    $fromLocation,
                    $toLocation,
                    $bookingDate,
                    $location,
                    $booking->booking_type,
                    $amountRefunded
                );
                $adminEmails = [config('mail.notify_tour'), config('mail.notify_info'), config('mail.notify_account')];
                foreach ($adminEmails as $adminEmail) {
                    SendEmailJob::dispatch($adminEmail, $admin);
                }
                $tourBooking->email_sent = true;
                $tourBooking->save();
            }
        }

        return redirect()->route('gentingBookings.details', ['id' => $booking->id])
            ->with('success', 'Booking canceled successfully.');
    }

    public function showVoucher($id)
    {
        return $this->gentingService->printVoucher($id);
    }

    public function showInvoice($id)
    {
        return $this->gentingService->printInvoice($id);
    }
}
