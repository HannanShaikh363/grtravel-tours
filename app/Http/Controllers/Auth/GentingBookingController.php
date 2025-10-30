<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AgentPricingAdjustment;
use App\Models\GentingRoomPassengerDetail;
use App\Models\GentingSurcharge;
use App\Services\GentingService;
use App\Tables\GentingBookingTableConfigurator;
use App\Models\User;
use App\Models\GentingHotel;
use App\Models\GentingBooking;
use App\Models\GentingRoomDetail;
use App\Models\Location;
use App\Models\GentingPackage;
use App\Models\GentingRate;
use App\Models\Booking;
use App\Mail\BookingCancel;
use App\Models\Country;
use App\Jobs\SendEmailJob;
use ProtoneMedia\Splade\SpladeTable;
use App\Mail\Genting\GentingBookingApproved;
use App\Mail\Genting\GentingBookingUnapproved;
use App\Models\CancellationPolicies;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use ProtoneMedia\Splade\Facades\Toast;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use carbon\carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;

class GentingBookingController extends Controller
{
    protected $gentingService;

    public function __construct(GentingService $gentingService)
    {
        $this->gentingService = $gentingService;
    }

    public function index()
    {
        return view('gentingBooking.index', [
            'genting_booking' => new GentingBookingTableConfigurator(),

        ]);
    }

    public function create(Request $request)
    {

        $validated = $request->validate([
            'travel_location' => 'nullable',
            'travel_date' => 'nullable|date',
            'destination' => 'nullable',
            'pick_time' => 'nullable|date_format:H:i:s',
            'vehicle_seating_capacity' => 'nullable|integer|min:1',
            'vehicle_luggage_capacity' => 'nullable|integer|min:1',
        ]);

        $adjustedRates = collect();
        $user = auth()->user();
        $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        if ($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('create booking')) {
            abort(403, 'This action is unauthorized.');
        }

        try {
            $parameters = $this->gentingService->extractRequestParametersAdmin($request);
            $query = $this->gentingService->buildQuery($parameters);
            $results = $this->gentingService->applyFiltersAndPaginate($query, $parameters);
            $adjustedRates = $this->gentingService->adjustGenting($results, $parameters);
            return $this->prepareResponse($request, $adjustedRates, $parameters);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()]);
        }
    }

    public function store(Request $request)
    {
        try {

            $validation = $this->gentingService->gentingFormValidationArray($request);
            $validator = Validator::make($request->all(), $validation['rules'], $validation['messages']);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $validatedData = $validator->validated();
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

            return response()->json([
                'success' => true,
                'message' => 'Booking request submitted!',
                'redirect_url' => route('gentingBookings.index'),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'An error occurred: ' . $e], 500);
        }
    }

    private function prepareResponse(Request $request, $adjustedRates, array $parameters)
    {
        $nextPageUrl = $adjustedRates->nextPageUrl();

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
        $package = GentingPackage::first();
        return view('gentingBooking.create', [
            // 'cancellationPolicy' => CancellationPolicies::where('active', 1)->where('type', 'genting')->first(),
            'showHotels' => $adjustedRates,
            'booking_date' => $parameters['check_in_out'],
            'filters' => $this->gentingService->getFilters($parameters, $adjustedRates),
            'next_page' => $nextPageUrl,
            'parameters' => $parameters,
            'package' => $package,
            'locationArray' => $locationArray,
        ]);
    }

    private function prepareResponse1(Request $request, $adjustedRates, array $parameters)
    {
        $currency = $parameters['currency'] ?? 'MYR';
        $tour_date = $request->input('travel_date');
        $tour_time = $request->input('travel_time');
        $locationName = $request->input('location');
        $countries = Country::all(['id', 'name'])->pluck('name', 'id');
        $location = Location::where('name', $locationName)->value('id');
        $nationalityId = $request->input('nationality');
        $nationality = $countries->get($nationalityId, 'N/A');
        $totalPrice = 0;
        $nextPageUrl = $adjustedRates->nextPageUrl();

        return view('gentingBooking.create', [
            'cancellationPolicy' => CancellationPolicies::where('active', 1)->where('type', 'tour')->first(),
            'showTours' => $adjustedRates,
            'booking_date' => $parameters['travel_date'],
            'booking_time' => $tour_time,
            'filters' => $this->tourService->getFilters($parameters, $adjustedRates),
            'currency' => $currency,
            'nationality' => $nationality,
            'countries' => $countries,
            'totalPrice' => $totalPrice,
            'location' => $location,
            'tour_date' => $tour_date,
            'next_page' => $nextPageUrl,
        ]);
    }

    public function gentingView(Request $request, $id, $pick_date, $currency, $rate, $room_details)
    {

        $roomDetails = json_decode($room_details, true);
        $user = auth()->user();
        $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        if ($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('create booking')) {
            abort(403, 'This action is unauthorized.');
        }

        $gentingHotels = GentingHotel::where('id', $id)->first();
        // Check if tour rate data exists
        if (!$gentingHotels) {
            return redirect()->back()->with('error', 'Genting Hotel not found');
        }

        $data = GentingRate::where('genting_hotel_id', $gentingHotels->id)
            ->whereHas('gentingPackage', function ($query) {
                $query->where('nights', '<', 2); // Only allow packages with less than 2 nights
            })
            ->where(function ($query) use ($roomDetails) {
                foreach ($roomDetails as $room) {
                    $totalCapacity = (int) $room['adult_capacity'] + (int) $room['child_capacity'];

                    $query->where('room_capacity', '>=', $totalCapacity);
                }
            })
            ->get();

        // Decode the JSON time_slots from the gentingHotels table
        $timeSlots = json_decode($gentingHotels->time_slots, true);  // Decode JSON into an array

        // Step 3: Get the selected time slot from the URL or request
        $selectedTimeSlot = $request->input('time_slots');

        // Check if the selected time slot is available in the time_slots array
        if ($selectedTimeSlot && !in_array($selectedTimeSlot, $timeSlots)) {
            return redirect()->back()->with('error', 'Invalid time slot selected');
        }

        // Assuming $data is a collection of GentingRate objects
        $totalRooms = count($roomDetails);

        $data->transform(function ($item) use ($currency, $totalRooms, $pick_date) {
            // Extract check-in and check-out dates
            $dates = explode(' to ', $pick_date);
            $checkIn = Carbon::parse($dates[0]);
            $checkOut = Carbon::parse($dates[1]);

            // Calculate number of nights
            $numNights = $checkIn->diffInDays($checkOut);

            if ($item) {
                $totalSurcharge = 0;
                // **APPLY SURCHARGES**
                $appliedWeekendSurcharge = false; // Ensure weekend surcharge is applied only once
                $dateRangeApplied = false; // Ensure date range surcharge is applied only once

                // Fetch surcharge details from genting_surcharges table
                $surchargeData = GentingSurcharge::where('genting_hotel_id', $item->genting_hotel_id)
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
                    // Loop through each night (excluding checkout date)
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

                // Calculate the price by multiplying with the number of rooms, nights, and adding surcharge
                $item->converted_price = app('App\Services\GentingService')
                    ->applyCurrencyConversion(($item->price * $numNights + $totalSurcharge) * $totalRooms, $item->currency, $currency);
            }

            return $item;
        });

        // Filter out items where 'converted_price' is not set
        $data = $data->filter(function ($item) {
            return isset($item->converted_price); // Only keep items where the 'converted_price' is set
        });



        // Split the highlights string by hyphen "\n" and trim extra spaces from each item
        $facilities = json_decode($gentingHotels->facilities, true);
        $description = $gentingHotels->descriptions;

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

        return view(
            'gentingBooking.gentingView',
            [
                'data' => $data,
                'gentingHotels' => $gentingHotels,
                'check_in_out' => $pick_date,
                'room_details' => $roomDetails,
                'currency' => $currency,
                'facilities' => $facilities,
                'paragraph' => $paragraph,
                'listItems' => $listItems,
                'booking_date' => $pick_date,
                'booking_slot' => $selectedTimeSlot,
                'countries' => Country::all(['id', 'name'])->pluck('name', 'id'),
            ]
        );
    }

    public function gentingBookingSubmission(Request $request, $id, $check_in_out, $currency, $room_details)
    {

        $roomDetails = json_decode($room_details, true);
        // dd($roomDetails);
        // Check if child_ages is "N/A", and if so, treat it as an empty array
        $user = auth()->user();
        $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        if ($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('create booking')) {
            abort(403, 'This action is unauthorized.');
        }

        // Fetch the related tour destination using the tour_destination_id from the $data
        $data = GentingRate::where('id', $id)->first();

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

            // Calculate total price with surcharges
            $genting_price = $this->gentingService->applyCurrencyConversion(
                ($data->price * $numNights + $totalSurcharge) * $totalRooms,
                $data->currency,
                $currency
            );
        } else {
            return redirect()->back()->with('error', 'Genting Hotel not found');
        }

        $gentingHotels = GentingHotel::where('id', $data->genting_hotel_id)->first();
        // Check if tour rate data exists
        if (!$gentingHotels) {
            return redirect()->back()->with('error', 'Genting Hotel not found');
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

        $passengers = array_reduce($roomDetails, function ($carry, $room) {
            return array_merge($carry, array_fill(0, count($room), [
                'title' => '',
                'full_name' => '',
                'phone_code' => ''
            ]));
        }, []);

        $passengers = [];

        foreach ($roomDetails as $index => $room) {
            $total = ($room['adult_capacity'] ?? 0) + ($room['child_capacity'] ?? 0);

            for ($i = 0; $i < $total; $i++) {
                $passengers[$index][$i] = [
                    'title' => '',
                    'full_name' => '',
                    'email_address' => '',
                    'contact_number' => '',
                    'child_age' => '',
                    'nationality_id' => '',
                ];
            }

            // Optional: set default bed selection per room
            $passengers[$index]['bed'] = 0;
        }

        $formDefaults = [
            'gentingHotels' => $data->gentingHotel,
            'hotel_name' => $data->gentingHotel->hotel_name,
            'package' => $data->gentingPackage->package,
            'check_in' => $check_in,
            'check_out' => $check_out,
            'roomDetails' => $roomDetails,
            'currency' => $currency,
            'total_cost' => $genting_price,
            'facilities' => $facilities,
            'entitlements' => $entitlements,
            'paragraph' => $paragraph,
            'listItems' => $listItems,
            'booking_slot' => $selectedTimeSlot,
            'room_capacity' => $data->room_capacity,
            'genting_rate_id' => $data->id,
            'room_type' => $data->room_type,
            'location_id' => $data->gentingHotel->location_id,
            'number_of_rooms' => count($roomDetails),
            'passengers' => $passengers,
        ];

        return view(
            'gentingBooking.partials.booking_form',
            [
                'formDefault' => $formDefaults,
                'data' => $data,
                'gentingHotels' => $data->gentingHotel,
                'check_in' => $check_in,
                'check_out' => $check_out,
                'roomDetails' => $roomDetails,
                'currency' => $currency,
                'total_cost' => $genting_price,
                'facilities' => $facilities,
                'entitlements' => $entitlements,
                'paragraph' => $paragraph,
                'listItems' => $listItems,
                'booking_slot' => $selectedTimeSlot,
                'hotel_name' => $data->gentingHotel->hotel_name,
                'room_capacity' => $data->room_capacity,
                'room_type' => $data->room_type,
                'genting_rate_id' => $data->id,
                'package' => $data->gentingPackage->package,
                'location_id' => $data->gentingHotel->location_id,
                'number_of_rooms' => count($roomDetails),
                'passengers' => $passengers,
                'countries' => Country::all(['id', 'name'])->pluck('name', 'id'),
            ]
        );
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
        $gentingBooking = GentingBooking::with('location.country')->where('booking_id', $id)->firstOrFail();
        $booking = Booking::where('id', $id)
            ->whereIn('booking_type', ['genting_hotel'])
            ->first();
        // Determine if the currently authenticated user is an admin
        $isCreatedByAdmin = $gentingBooking->created_by_admin; // Assuming this field exists to track creation by admin

        // Approve the booking
        $gentingBooking->approved = true;
        $gentingBooking->sent_approval = false;
        $booking->booking_status = 'confirmed';
        $booking->save();
        $gentingBooking->save();

        // Check if the booking was not created by admin and if the email has not been sent yet
        if (!$isCreatedByAdmin) {

            $agentInfo = User::where('id', $gentingBooking->user_id)->first(['email', 'first_name']);
            $agentEmail = $agentInfo->email;
            $agentName = $agentInfo->first_name; // Get the agent's name

            $mailInstance = new GentingBookingApproved($gentingBooking, $agentName, $booking->booking_unique_id);
            SendEmailJob::dispatch($agentEmail, $mailInstance);
            $dropOffName = null;
            $pickUpName = null;
            $is_updated = null;
            // Mark the email as sent
            $gentingBooking->email_sent = true;
            $gentingBooking->save();
            $deadlineDate = $request->input('date'); // e.g., 2025-04-14
            $deadlineTime = $request->input('time'); // e.g., 13:00

            $booking->deadline_date = Carbon::createFromFormat('Y-m-d H:i', $deadlineDate . ' ' . $deadlineTime)->format('Y-m-d H:i:s');
            $booking->save();
            app(GentingService::class)->sendVoucherEmail(request(), $gentingBooking, $is_updated);

            Toast::title('Booking Approved successfully')
                ->success()
                ->rightBottom()
                ->autoDismiss(5);
            return redirect()->back()->with('success', 'Booking Approved successfully.');
        }
    }

    public function changeDeadline(Request $request, $id)
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
        $gentingBooking = GentingBooking::with('location.country')->where('booking_id', $id)->firstOrFail();
        $booking = Booking::where('id', $id)
            ->whereIn('booking_type', ['genting_hotel'])
            ->first();
        // Determine if the currently authenticated user is an admin
        $isCreatedByAdmin = $gentingBooking->created_by_admin; // Assuming this field exists to track creation by admin

        // Check if the booking was not created by admin and if the email has not been sent yet
        if (!$isCreatedByAdmin) {
            $dropOffName = null;
            $pickUpName = null;
            $is_updated = 1;
            $gentingBooking->save();
            $deadlineDate = $request->input('date'); // e.g., 2025-04-14
            $deadlineTime = $request->input('time'); // e.g., 13:00

            $booking->deadline_date = Carbon::createFromFormat('Y-m-d H:i', $deadlineDate . ' ' . $deadlineTime)->format('Y-m-d H:i:s');
            $booking->save();
            app(GentingService::class)->sendVoucherEmail(request(), $gentingBooking, $is_updated);

            Toast::title('Deadline changed successfully')
                ->success()
                ->rightBottom()
                ->autoDismiss(5);
            return redirect()->back()->with('success', 'Deadline changed successfully.');
        }
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

    public function viewDetails($id)
    {
        $booking = GentingBooking::with(['location.country', 'users.company'])->where('booking_id', $id)->firstOrFail();
        $roomDetails = GentingRoomDetail::where('booking_id', $booking->id)->with('passengers')->get();
        $passengerQuery = GentingRoomPassengerDetail::query()
            ->select(
                'genting_room_passenger_details.*',
                'genting_room_details.room_no',
                'genting_room_details.extra_bed_for_child'
            )
            ->join('genting_room_details', 'genting_room_passenger_details.room_detail_id', '=', 'genting_room_details.id')
            ->where('genting_room_details.booking_id', $booking->id)
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
        $hotels = GentingHotel::pluck('hotel_name', 'id');
        $bookedHotel = null;
        if ($booking && $booking->gentingRate && $booking->gentingRate->gentingHotel) {
            $bookedHotel = $booking->gentingRate->gentingHotel->id;
        }
        // echo "<pre>";print_r($bookedHotel);die();
        // $bookedHotel = optional($booking->gentingRate->gentingHotel)->id ?? null;
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
        return view('gentingBooking.details', compact(
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

    public function showVoucher($id)
    {
        return $this->gentingService->printVoucher($id);
    }

    public function showInvoice($id)
    {
        return $this->gentingService->printInvoice($id);
    }

    public function roomEdit(Request $request, $booking_id, $room_no)
    {
        try {
            // Fetch the booking record
            $passengerDetails = GentingRoomPassengerDetail::where('id', $booking_id)->where('room_detail_id', $room_no)->first();
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
            $this->gentingService->sendVoucherEmail($request, $passengerDetails->roomDetail->booking, $is_updated);
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

    //opens form for changing booking info
    public function toggleEditForm($id)
    {
        $booking = GentingBooking::findOrFail($id);

        // Check if the form is currently displayed
        if (session()->has('gentingEditForm')) {
            // Toggle the session value
            session()->forget('gentingEditForm');
        } else {
            // Set the session value to true, showing the edit form
            session(['gentingEditForm' => true]);
        }

        // Redirect back to the same page to show the form
        return redirect()->back();
    }

    public function listPackages(Request $request)
    {
        $packages = GentingPackage::all(['id', 'package'])->map(function ($package) {
            return [
                'id' => $package->id,
                'package' => html_entity_decode($package->package, ENT_QUOTES, 'UTF-8'),
            ];
        });

        return response()->json($packages);
    }

    public function getPrice($id, $booking_id)
    {
        // Fetch the genting room by ID
        $genting = GentingRate::find($id);

        if (!$genting) {
            return response()->json(['message' => 'Room not found'], 404);
        }

        // Fetch the booking data
        $booking = GentingBooking::find($booking_id);

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

        $totalSurcharge = 0;

        // Fetch surcharge details from genting_surcharges table
        $surchargeData = GentingSurcharge::where('genting_hotel_id', $genting->genting_hotel_id)
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

            $appliedWeekendSurcharge = false;
            $currentDate = clone $checkIn;

            // Loop through each night of the stay (excluding checkout date)
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

        // Calculate final price with surcharge
        $convertedPrice = $this->gentingService->applyCurrencyConversion(
            ($genting->price * $numNights + $totalSurcharge) * $numberOfRooms,
            $genting->currency,
            $currency
        );

        // Apply agent pricing adjustment (if available)
        $adjustmentRates = AgentPricingAdjustment::getCurrentAdjustmentRate($booking->user_id);

        foreach ($adjustmentRates as $adjustmentRate) {
            if ($adjustmentRate->transaction_type === 'genting_hotel') {
                $convertedPrice = $this->gentingService->applyAdjustment($convertedPrice, $adjustmentRate);
            }
        }

        // Return formatted room details
        return response()->json([
            'id' => $genting->id,
            'price' => round($convertedPrice, 2), // optional: round to 2 decimals
        ]);

    }


    public function listRooms($id, $booking_id)
    {
        // echo "<pre>";print_r($id);die();
        // Fetch the genting rooms by hotel ID and package ID
        $genting = GentingRate::where('genting_hotel_id', $id)
            ->whereHas('gentingPackage', function ($query) {
                $query->where('nights', '<', 2); // Only allow packages with less than 2 nights
            })
            ->get();

        if ($genting->isNotEmpty()) {
            // Fetch the booking data to get the currency and number of rooms
            $booking = GentingBooking::find($booking_id);

            if ($booking) {
                $gentingRoomDetails = GentingRoomDetail::where('booking_id', $booking->id)->get();
                // Get currency and number of rooms
                $currency = $booking->currency;
                $numberOfRooms = $booking->number_of_rooms;
                $checkIn = Carbon::parse($booking->check_in);
                $checkOut = Carbon::parse($booking->check_out);
                // Calculate number of nights
                $numNights = $checkIn->diffInDays($checkOut);

                $totalSurcharge = 0;

                // Fetch surcharge details from genting_surcharges table
                $surchargeData = GentingSurcharge::where('genting_hotel_id', $id)
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
                    // dd($weekendDays);

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

                // Group passengers by room_no and count them
                $passengerCountsByRoom = $gentingRoomDetails
                    ->groupBy('room_no')
                    ->map(fn($group) => $group->count());

                // Map and filter available rooms based on capacity
                $rooms = $genting->filter(function ($room) use ($passengerCountsByRoom) {
                    foreach ($passengerCountsByRoom as $roomNo => $passengerCount) {
                        if ($room->room_capacity >= $passengerCount) {
                            return true;
                        }
                    }
                    return false;
                })->map(function ($room) use ($currency, $numberOfRooms, $totalSurcharge, $numNights, $booking) {
                    $convertedPrice = $this->gentingService->applyCurrencyConversion(
                        ($room->price * $numNights + $totalSurcharge) * $numberOfRooms,
                        $room->currency,
                        $currency
                    );

                    $adjustmentRates = AgentPricingAdjustment::getCurrentAdjustmentRate($booking->user_id);
                    foreach ($adjustmentRates as $adjustmentRate) {
                        if ($adjustmentRate->transaction_type === 'genting_hotel') {
                            $convertedPrice = $this->gentingService->applyAdjustment($convertedPrice, $adjustmentRate);
                        }
                    }

                    $room->converted_price = $convertedPrice;
                    return [
                        'id' => $room->id,
                        'room_type' => $room->room_type,
                        'price' => $convertedPrice,
                        'currency' => $currency,
                        'bed_count' => $room->bed_count,
                        'formattedLabel' => $room->room_type . ' - ' . $currency . ' ' . $convertedPrice . ' (' . $room->bed_count . ' beds)',
                    ];
                });


                return response()->json($rooms);
            }

            return response()->json(['message' => 'Booking not found'], 404);
        }

        // Return a 404 response if no rooms are found
        return response()->json(['message' => 'Rooms not found'], 404);
    }


    public function infoEdit(Request $request, $id)
    {
        try {
            // Validate the request
            $validated = $request->validate([
                'total_cost' => 'required|numeric|min:0', // Ensure it's required, numeric, and not negative
                'rooms' => 'required|exists:genting_rates,id' // Ensure room ID exists in the genting_rates table
            ]);
            // Fetch the booking record
            $gentingBooking = GentingBooking::with('location.country')->findOrFail($id); // Throws 404 if not found
            $booking = Booking::where('booking_type_id', $id)->first();
            // Fetch the selected GentingRate based on the provided room ID
            $rates = GentingRate::find($request->rooms);

            if (!$rates) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected room rate not found.'
                ], 404);
            }

            $gentingBookingUpdated = $gentingBooking->update([
                'hotel_name' => $rates->gentingHotel->hotel_name,
                'room_type' => $rates->room_type,
                'total_cost' => $validated['total_cost'],
                'genting_rate_id' => $rates->id,
                'package' => $rates->gentingPackage->package,
            ]);

            $bookingUpdated = $booking->update([
                'amount' => $validated['total_cost'],
            ]);

            if ($gentingBookingUpdated && $bookingUpdated) {
                Log::info('Genting booking and booking amount updated successfully.', [
                    'booking_id' => $booking->id,
                    'genting_booking_id' => $gentingBooking->id,
                    'amount' => $validated['total_cost'],
                ]);
            } else {
                Log::error('Update failed: One or both updates did not return true.', [
                    'booking_id' => $booking->id,
                    'genting_booking_id' => $gentingBooking->id,
                ]);
            }

            // Send email notification
            $is_updated = 1;
            $this->gentingService->sendVoucherEmail($request, $gentingBooking, $is_updated);

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

    public function toggleEditReservationForm($id)
    {
        $booking = GentingBooking::findOrFail($id);

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
        $booking = GentingBooking::with('location.country')->find($booking);
        $request = request();
        $request->validate($this->reservationFormValidation($request));

        $booking->update([
            'confirmation_id' => $request->input('confirmation_id'),
            'reservation_id' => $request->input('reservation_id')
        ]);

        $is_updated = 1;

        $this->gentingService->sendVoucherEmail($request, $booking, $is_updated);

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


}
