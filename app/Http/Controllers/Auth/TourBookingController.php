<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AgentPricingAdjustment;
use App\Models\City;
use App\Models\Driver;
use App\Models\Country;
use App\Models\FlightDetail;
use App\Models\Location;
use App\Models\Tour;
use App\Models\TourBooking;
use App\Models\Booking;
use App\Models\TourRate;
use App\Models\TourDestination;
use App\Models\User;
use App\Tables\TourBookingTableConfigurator;
use GuzzleHttp\Promise\Create;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use App\Services\TourService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use ProtoneMedia\Splade\Facades\Toast;
use ProtoneMedia\Splade\SpladeTable;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use App\Models\CancellationPolicies;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use carbon\carbon;
use App\Mail\Tour\TourBookingApproved;
use App\Mail\Tour\TourTicketUploadMail;
use Illuminate\Support\Facades\Storage;
use App\Jobs\SendEmailJob;

class TourBookingController extends Controller
{
    protected $tourService;

    public function __construct(TourService $tourService)
    {
        $this->tourService = $tourService;
    }

    public function index()
    {
        return view('tourBooking.index', [
            'tour_booking' => new TourBookingTableConfigurator(),

        ]);
    }

    public function create2(Request $request)
    {
        // Validate the input fields
        $validated = $request->validate([
            'location' => 'nullable',
            'tour_date' => 'nullable|date',
            'seating_capacity' => 'nullable|integer|min:1',
            'luggage_capacity' => 'nullable|integer|min:1',
            'number_of_adults' => 'nullable|integer|min:0',
            'number_of_children' => 'nullable|integer|min:0',
        ]);

        $countries = Country::all(['id', 'name'])->pluck('name', 'id');
        $locationName = $request->input('location');
        $tour_date = $request->input('tour_date');
        $nationalityId = $request->input('nationality');
        $nationality = $countries->get($nationalityId, 'N/A');

        // Get location ID based on location name
        $location = Location::where('name', $locationName)->value('id');

        // Start with the latest 5 tours
        $showTours = Tour::latest()->take(5);

        // Check if a location is provided and apply the location filter
        if ($location) {
            $showTours->where('location_id', $location);
        }

        // Check if seating_capacity is provided and greater than 0
        if ($request->filled('seating_capacity') && $request->input('seating_capacity') > 0) {
            $showTours->where('seating_capacity', $request->input('seating_capacity'));
        }

        // Check if luggage_capacity is provided and greater than 0
        if ($request->filled('luggage_capacity') && $request->input('luggage_capacity') > 0) {
            $showTours->where('luggage_capacity', $request->input('luggage_capacity'));
        }

        // Execute the query
        $showTours = $showTours->get();

        // Calculate booking cost based on number of adults and children
        $totalBookingCost = 0;
        $totalPrice = 0;
        $numberOfAdults = 0;
        $numberOfChildren = 0;
        foreach ($showTours as $tour) {
            $numberOfAdults = $request->input('number_of_adults', 0);
            $numberOfChildren = $request->input('number_of_children', 0);
            $adultPrice = $tour->adult; // assuming this is the column name
            $childPrice = $tour->child; // assuming this is the column name

            $totalBookingCost += ($numberOfAdults * $adultPrice) + ($numberOfChildren * $childPrice);
            $price = $tour->price;
            $totalPrice = $totalBookingCost + $price;
        }


        return view('tourBooking.create', [
            'showTours' => $showTours,
            'tour_date' => $tour_date,
            'location' => $location,
            'countries' => $countries,
            'nationality' => $nationality,
            'totalPrice' => $totalPrice,
            'numberOfAdults' => $numberOfAdults,
            'numberOfChildren' => $numberOfChildren,
        ]);
    }

    public function create(Request $request)
    {

        // Validate the input fields
        $validated = $request->validate([
            'location' => 'nullable',
            'nationality' => 'nullable',
            'currency' => 'nullable',
            'tour_date' => 'nullable|date',
            'number_of_adults' => 'nullable|integer',
            'child_capacity' => 'nullable|integer',
        ]);

        $adjustedRates = collect();
        $user = auth()->user();
        $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        if ($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('create booking')) {
            abort(403, 'This action is unauthorized.');
        }

        try {
            $parameters = $this->tourService->extractRequestParameters($request);

            $query = $this->tourService->buildQuery($parameters);
            $results = $this->tourService->applyFiltersAndPaginate($query, $parameters);

            $adjustedRates = $this->tourService->adjustTours($results, $parameters);

            return $this->prepareResponse($request, $adjustedRates, $parameters);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()]);
        }
    }

    private function prepareResponse(Request $request, $adjustedRates, array $parameters)
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

        // if ($request->ajax()) {
        //     return response()->json([
        //         'html' => view('tourBooking.partials.tourList', [
        //             'showTours' => $adjustedRates,
        //             'cancellationPolicy' => CancellationPolicies::getActivePolicyByType('transfer'),
        //             'pick_date' =>$tour_date,
        //             'pick_time' => $tour_time,
        //             'currency' => $currency,
        //             'tour_date' => $tour_date,
        //             'totalPrice' => $totalPrice,
        //             'location' => $location,
        //             'nationality' => $nationality,
        //             'countries' => $countries
        //         ])->render(),
        //         'next_page' => $nextPageUrl,
        //     ]);
        // }

        return view('tourBooking.create', [
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


    
    public function tourSubmission($id, $pick_date, $pick_time, $currency, $rate, $adult_capacity, $child_capacity, $infant_capacity, $child_ages)
    {
        $user = auth()->user();

        $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        if ($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('create booking')) {
            abort(403, 'This action is unauthorized.');
        }

        $data = TourRate::where('id', $id)->first();

        $tourDestination = TourDestination::where('id', $data->tour_destination_id)->first();
        $tour_price = $data->price + ($tourDestination->adult * $adult_capacity) + ($tourDestination->child * $child_capacity);
        $adjustmentRate = AgentPricingAdjustment::getCurrentAdjustmentRate(auth()->id());
        $tour_price = $this->tourService->applyCurrencyConversion($tour_price, $data->currency, $currency);
        $tour_price = $this->tourService->applyAdjustment($tour_price, $adjustmentRate);
        // Decode the JSON time_slots from the tourDestination table
        $timeSlots = json_decode($tourDestination->time_slots, true);  // Decode JSON into an array

        // // Step 3: Get the selected time slot from the URL or request
        // $selectedTimeSlot = $booking_slot;

        // // Check if the selected time slot is available in the time_slots array
        // if ($selectedTimeSlot && !in_array($selectedTimeSlot, $timeSlots)) {
        //     return redirect()->back()->with('error', 'Invalid time slot selected');
        // }

        if ($data->sharing === 1) {
            // Multiply price per person when sharing is enabled
            $tour_price = ($data->price * $adult_capacity) + ($data->price * $child_capacity) + ($tourDestination->adult * $adult_capacity);
            +($tourDestination->child * $child_capacity);
        } else {
            // Use the standard pricing method
            $tour_price = $data->price + ($tourDestination->adult * $adult_capacity) + ($tourDestination->child * $child_capacity);
        }

        // Split the highlights string by hyphen "\n" and trim extra spaces from each item
        $highlights = array_filter(array_map('trim', explode("---", $data->highlights)));
        $important_info = array_filter(array_map('trim', explode("---", $data->important_info)));

        if ($pick_time && $pick_date) {

            // Set the target date and time
            $targetDate = Carbon::createFromFormat('Y-m-d H:i:s', $pick_date . ' ' . $pick_time); // Replace with your target date and time
            // Get the current date and time
            $currentDate = Carbon::now();
            // Calculate the difference in days
            $remainingDays = $currentDate->diffInDays($targetDate, false); // 'false' to allow negative values if targetDate is in the past
        }
        $remainingDays = $remainingDays ?? 0;

        // Combine pick_date and pick_time into a Carbon instance
        $pick_datetime = Carbon::parse("$pick_date $pick_time");
        // Get the current time
        $current_time = Carbon::parse(convertToUserTimeZone(Carbon::now()));
        // Calculate the difference in hours
        $time_diff_in_hours = $current_time->diffInHours($pick_datetime);
        // Determine if the time difference is more than 12 hours
        $is_within_24_hours = $time_diff_in_hours > 0 && $time_diff_in_hours <= 48;
        $formDefaults = [
            'pickup_time' => old('pickup_time', $pick_time ?? '09:59:36'),
            'tour_date' => old('pick_date', $pick_date ?? '2025-01-15'),
            'total_cost' => old('total_cost', $tour_price ?? $data->price ?? 0),
            'currency' => old('currency', $currency ?? 'USD'),
            'seating_capacity' => old('seating_capacity', $data->seating_capacity ?? 4),
            'package' => old('package', $data->package ?? 'N/A'),
            'location_id' => old('location_id', $tourDestination->location->id ?? 1),
            'hours' => old('hours', $tourDestination->hours ?? 2),
            'tour_name' => old('tour_name', $tourDestination->name ?? 'Tour Name'),
            'tour_rate_id' => old('tour_rate_id', $data->id ?? 1),
            'number_of_adults' => old('number_of_adults', $adult_capacity ?? 1),
            'number_of_children' => old('number_of_children', $child_capacity ?? 0),
            'booking_slot' => old('booking_slot', $booking_slot ?? 0),
        ];
        return view(
            'tourBooking.partials.booking_form',
            [
                'formDefault' => $formDefaults,
                'data' => $data,
                'tourDestination' => $tourDestination,
                'tour_date' => $pick_date,
                'pickup_time' => $pick_time,
                'adult_capacity' => $adult_capacity,
                'child_capacity' => $child_capacity,
                'currency' => $currency,
                'price' => $rate,
                'highlights' => $highlights,
                'important_info' => $important_info,
                'remainingDays' => $remainingDays,
                'is_within_24_hours' => $is_within_24_hours,
                'booking_slot' => $timeSlots ?? 'N/A',
                'cancellationPolicy' => CancellationPolicies::where('active', 1)->where('type', 'tour')->first(),
                'countries' => Country::all(['id', 'name'])->pluck('name', 'id'),
            ]
        );
    }

    public function ticketSubmission(Request $request, $id, $pick_date, $pick_time, $currency, $rate, $adult_capacity, $child_capacity, $infant_capacity, $child_ages)
    {
        $childAges = json_decode(rawurldecode($child_ages), true);
        $user = auth()->user();
        $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        if ($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('create booking')) {
            abort(403, 'This action is unauthorized.');
        }

        // Fetch the related tour destination using the tour_destination_id from the $data
        $tourDestination = TourDestination::where('id', $id)->first();

        // Check if tour destination data exists
        if (!$tourDestination) {
            return redirect()->back()->with('error', 'Tour destination not found');
        }

        // Decode the JSON time_slots from the tourDestination table
        $timeSlots = json_decode($tourDestination->time_slots, true);  // Decode JSON into an array

        $tour_price = $tourDestination->adult * $adult_capacity + $tourDestination->child * $child_capacity;

        $adjustmentRate = AgentPricingAdjustment::getCurrentAdjustmentRate(auth()->id());

        $tour_price = $this->tourService->applyCurrencyConversion($tour_price, $tourDestination->ticket_currency ?? 'MYR', $currency);

        $tour_price = $this->tourService->applyAdjustment($tour_price, $adjustmentRate);

        if ($pick_time && $pick_date) {

            // Set the target date and time
            $targetDate = Carbon::createFromFormat('Y-m-d H:i:s', $pick_date . ' ' . $pick_time); // Replace with your target date and time
            // Get the current date and time
            $currentDate = Carbon::now();
            // Calculate the difference in days
            $remainingDays = $currentDate->diffInDays($targetDate, false); // 'false' to allow negative values if targetDate is in the past
        }
        $remainingDays = $remainingDays ?? 0;

        // Combine pick_date and pick_time into a Carbon instance
        $pick_datetime = Carbon::parse("$pick_date $pick_time");
        // Get the current time
        $current_time = Carbon::parse(convertToUserTimeZone(Carbon::now()));
        // Calculate the difference in hours
        $time_diff_in_hours = $current_time->diffInHours($pick_datetime);
        // Determine if the time difference is more than 12 hours
        $is_within_24_hours = $time_diff_in_hours > 0 && $time_diff_in_hours <= 48;

        $formDefaults = [
            'pickup_time' => old('pickup_time', $pick_time ?? '09:59:36'),
            'tour_date' => old('pick_date', $pick_date ?? '2025-01-15'),
            'total_cost' => old('total_cost', $tour_price ?? 0),
            'currency' => old('currency', $currency ?? 'USD'),
            'seating_capacity' => old('seating_capacity', $data->seating_capacity ?? 4),
            'location_id' => old('location_id', $tourDestination->location->id ?? 1),
            'hours' => old('hours', $tourDestination->hours ?? 2),
            'tour_name' => old('tour_name', $tourDestination->name ?? 'Tour Name'),
            'number_of_adults' => old('number_of_adults', $adult_capacity ?? 1),
            'number_of_children' => old('number_of_children', $child_capacity ?? 0),
            'time_slots' => old('timeSlots', $timeSlots ?? 0),
            'is_within_24_hours' => $is_within_24_hours,
            'tour_destination_id' => old('tour_destination_id',$tourDestination->id)
        ];
        return view(
            'tourBooking.partials.ticketBooking_form',
            [
                'formDefault' => $formDefaults,
                'tourDestination' => $tourDestination,
                'tour_date' => $pick_date,
                'pick_time' => $pick_time,
                'adult_capacity' => $adult_capacity,
                'child_capacity' => $child_capacity,
                'infant_capacity' => $infant_capacity,
                'child_ages' => $childAges,
                'currency' => $currency,
                'price' => $tour_price,
                'remainingDays' => $remainingDays,
                'is_within_24_hours' => $is_within_24_hours,
                'booking_slot' => $timeSlots ?? 'N/A',
                'cancellationPolicy' => CancellationPolicies::where('active', 1)->where('type', 'tour')->first(),
                'countries' => Country::all(['id', 'name'])->pluck('name', 'id'),
            ]
        );
    }

    public function storeTicket(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), $this->tourService->ticketFormValidationArray($request));
            $user = auth()->user();
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(), // Send validation errors as a JSON object
                ], 422);
            }

            $tourBooking = $this->tourService->storeTicket($request);
            $tourBooking = json_decode($tourBooking->getContent(), true);
            // dd($tourBooking,$tourBooking['success']);
            if (!$tourBooking['success']) {

                Toast::title('Ticket Booked Successfully')
                    ->danger()
                    ->rightTop()
                    ->autoDismiss(5);

                if ($request->ajax()) {
                    return $tourBooking;
                }
            }

            // if ($request->submitButton == "pay_online") {
            //     return response()->json([
            //         'success' => true,
            //         'message' => 'pay_online',
            //         'redirect_url' => $this->processPayment($bookingSaveData),
            //     ]);
            // }

            if ($request->submitButton == "pay_offline") {

                Toast::title('Payment Done.')
                    ->danger()
                    ->rightTop()
                    ->autoDismiss(5);
            }
            session()->flash('success', 'Booking successfully created!');
            Toast::title('Ticket Booking Created')
                ->success()
                ->rightTop()
                ->autoDismiss(5);
            return response()->json([
                'success' => true,
                'message' => 'Booking successfully created!',
                'redirect_url' => route('tourbookings.index'),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'An error occurred: ' . $e], 500);
        }
    }


    public function store(Request $request): JsonResponse
    {

        try {

            $validator = Validator::make($request->all(), $this->tourService->tourFormValidationArray($request));
            $user = auth()->user();
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(), // Send validation errors as a JSON object
                ], 422);
            }

            $tourBooking = $this->tourService->storeBooking($request);

            $tourBooking = json_decode($tourBooking->getContent(), true);

            if (!$tourBooking['success']) {

                Toast::title($e->getMessage())
                    ->danger()
                    ->rightTop()
                    ->autoDismiss(5);

                if ($request->ajax()) {
                    return $tourBooking;
                }

                return Redirect::back()->withErrors(['error' => $e->getMessage()]);
            }

            // if ($request->submitButton == "pay_online") {
            //     return response()->json([
            //         'success' => true,
            //         'message' => 'pay_online',
            //         'redirect_url' => $this->processPayment($bookingSaveData),
            //     ]);
            // }

            if ($request->submitButton == "pay_offline") {

                Toast::title('Payment Done.')
                    ->danger()
                    ->rightTop()
                    ->autoDismiss(5);
            }
            session()->flash('success', 'Booking successfully created!');
            Toast::title('Tour Booking Created')
                ->success()
                ->autoDismiss(5);
            return response()->json([
                'success' => true,
                'message' => 'Booking successfully created!',
                'redirect_url' => route('tourbookings.index'),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'An error occurred: ' . $e], 500);
        }
    }




    public function edit($id)
    {

        $tourBooking = TourBooking::findOrFail($id);

        $tourIds = explode(',', $tourBooking->tour_id);

        $combinedTours = array_merge([$tourBooking->parent_id], $tourIds);

        return view('tourBooking.edit', [
            'tour_booking' => $tourBooking,
            'selectedTours' => $combinedTours,
            'cancellationPolicy' => CancellationPolicies::where('active', 1)->where('type', 'transfer')->first(),
            'countries' => Country::all(['id', 'name'])->pluck('name', 'id'),
        ]);
    }

    public function update($id)
    {
        $request = request();
        // $request->validate($this->tourValidationArray());
        $tour = TourBooking::with('location.country')->findOrFail($id);

        $request->validate([
            'passenger_full_name' => 'nullable|string|max:255',
            'passenger_email_address' => 'nullable|email|max:255',
            'passenger_contact_number' => 'nullable',
        ]);

        $booking = $this->tourService->updatePassenger($request, $tour);
        session()->forget('toureditForm');
        session()->save();

        // $tour->update($this->tourBookingFormData($request));
        Toast::title('Tour Booking updated')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);

        return Redirect::route('tour_booking.index')->with('status', 'Tour-updated');
    }

    public function tourValidationArray()
    {
        return [
            'passenger_full_name' => 'required',
            'passenger_email_address' => 'required',
            'passenger_contact_number' => 'required',
            'tour_date' => 'required',
            'nationality' => 'nullable',
        ];
    }

    public function tourBookingFormData(mixed $request)
    {
        // Ensure parent_id is an array
        $parentIds = is_array($request->parent_id) ? $request->parent_id : explode(',', $request->parent_id);

        // Separate the first selected ID as parent_id and the rest as tour_ids
        $parentId = $parentIds[0];
        $tourIds = array_slice($parentIds, 1);

        // Fetch the tour using the parent_id
        $parentTour = Tour::find($parentId);

        // Initialize the total cost with the parent tour's booking cost
        $totalCost = $parentTour ? $parentTour->price : 0;

        // Fetch all the tours for the remaining tour_ids and sum their booking costs
        if (!empty($tourIds)) {
            $additionalTours = Tour::whereIn('id', $tourIds)->get();
            foreach ($additionalTours as $tour) {
                $totalCost += $tour->price;
            }
        }

        // Prepare the booking data
        $bookingData = [
            'transport_id' => $request->transport_id,
            'vehicle_seating_capacity' => $request->vehicle_seating_capacity,
            'vehicle_luggage_capacity' => $request->vehicle_luggage_capacity,
            'passenger_full_name' => $request->passenger_full_name,
            'passenger_email_address' => $request->passenger_email_address,
            'passenger_contact_number' => $request->passenger_contact_number,
            'location_id' => $request->location_id,
            'tour_id' => implode(',', $tourIds), // Join remaining tour IDs into a comma-separated string
            'booking_date' => now(),
            'tour_date' => $request->tour_date,
            'total_cost' => $totalCost,
            'flight_number' => $request->flight_number,
            'airline_iata' => $request->airline_iata,
            'nationality' => $request->nationality,
            // '' => $request->airline_iata,
            'user_id' => auth()->id(),
        ];

        return $bookingData;
    }


    public function getFlightDetails(Request $request)
    {
        // Validate the input
        $request->validate([
            'flight_number' => 'required|string',
            'airline_iata' => 'required|string',
        ]);

        // Extract flight number and airline code from the request
        $flightNumber = $request->input('flight_number');
        $airlineCode = $request->input('airline_iata');
        // $date = $request->input('tour_date');

        // Call the flight API to fetch flight details
        // Replace with actual flight API URL and API key
        $apiUrl = 'https://api.aviationstack.com/v1/timetable';
        $apiKey = 'b83e736e33b90936a453ef510e82ea2a'; // Replace with your actual API key

        try {
            // Make the API request
            $response = Http::withHeaders([
                'Authorization' => "Bearer $apiKey",
            ])->get($apiUrl, [
                'flight_num' => $flightNumber,
                'iataCode'  => $airlineCode,
                'type'  => 'arrival',
                // 'date'  => $date,
            ]);

            // Check if the response was successful
            if ($response->successful()) {
                $flightData = $response->json();
                // Assuming the flight data is in the `data` key of the response
                return response()->json([
                    'success' => true,
                    'data' => $flightData['data'],
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Could not fetch flight details',
                ], 400);
            }
        } catch (\Exception $e) {
            // Handle any exceptions during the API call
            return response()->json([
                'success' => false,
                'message' => 'An error occurred: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function viewDetails($id)
    {
        // Load cancellation booking policies dynamically
        $cancellation = CancellationPolicies::where('active', 1)->get();
        // Fetch the booking details by ID
        $booking = TourBooking::with('location.country')->where('booking_id', $id)->firstOrFail();
        $country_code = data_get($booking, 'location.country.iso2');
        $booking_timezone = getTimezoneAbbreviationFromCountryCode($country_code);
        // $tour_rates = TourRate::findOrFail($booking->tour_rate_id);

        // $tour_destination = TourDestination::findOrFail($tour_rates->tour_destination_id);

        $nationality = Country::where('id', $booking->nationality_id)->value('name');

        $currency = $booking->currency;
        $bookingStatus = Booking::where('booking_type_id', $booking->id)
            ->whereIn('booking_type', ['tour', 'ticket']) // Add condition for booking_type
            ->first();
        $user = User::where('id', $booking->user_id)->first();
        $createdBy = (($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: 'Admin';

        // Default
        $companyName = '';

        // Check if user has an agent_code
        if ($user->type == 'staff') {
            // Find the main agent by matching agent_code (assuming main agent is the one with is_agent = true or has company relation)
            $mainAgent = User::where('agent_code', $user->agent_code)
                ->whereHas('company') // Assuming only main agent has company
                ->first();

            if ($mainAgent && $mainAgent->company) {
                $companyName = $mainAgent->company->agent_name;
            }
        }
        // $location = Location::where('id', $tour_destination->location_id)->value('name');

        $countries = Country::all(['id', 'name'])->pluck('name', 'id');

        $cancellationBooking = $cancellation->filter(function ($policy) use ($bookingStatus) {
            return $policy->type == $bookingStatus->booking_type;
        });

        $targetDate = Carbon::createFromFormat('Y-m-d H:i:s', $bookingStatus->service_date); // Replace with your target date and time
        // Get the current date and time
        $currentDate = Carbon::now();
        // Calculate the difference in days
        $remainingDays = $currentDate->diffInDays($targetDate, false); // 'false' to allow negative values if targetDate is in the past
        // $user = auth()->user();
        $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        $can_edit = !($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('update booking'));
        $can_delete = !($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('delete booking'));
        // dd($bookingStatus->booking_type);
        $fullRefund = route('fullRefund', ['service_id' => $bookingStatus->id, 'service_type' => $bookingStatus->booking_type]);
        $offline_payment = route('tourOfflineTransaction');
        $userWallet = $user->credit_limit_currency.' '.number_format($user->credit_limit, 2);
        // Return the view with booking details
        return view('tourBooking.details', compact(
            'booking',
            'countries',
            'nationality',
            // 'location',
            // 'tour_destination',
            // 'tour_rates',
            'currency',
            'createdBy',
            'bookingStatus',
            'cancellationBooking', // Pass the filtered cancellation policies
            'remainingDays',
            'can_edit',
            'can_delete',
            'fullRefund',
            'booking_timezone',
            'companyName',
            'offline_payment',
            'userWallet',
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
        $tourBooking = TourBooking::with('location.country')->where('booking_id', $id)->firstOrFail();
        $booking = Booking::where('id', $id)
            ->whereIn('booking_type', ['tour', 'ticket'])
            ->first();

        // Determine if the currently authenticated user is an admin
        $isCreatedByAdmin = $tourBooking->created_by_admin; // Assuming this field exists to track creation by admin

        // Approve the booking
        $tourBooking->approved = true;
        $tourBooking->sent_approval = false;
        $booking->booking_status = 'confirmed';
        $booking->save();
        $tourBooking->save();

        // Check if the booking was not created by admin and if the email has not been sent yet
        if (!$isCreatedByAdmin && !$tourBooking->email_sent) {
            if ($tourBooking->type === 'ticket') {
                $agentInfo = User::where('id', $tourBooking->user_id)->first(['email', 'first_name']);
                $agentEmail = $agentInfo->email;
                $agentName = $agentInfo->first_name; // Get the agent's name

                // Send the booking approval email to the agent
                // Mail::to($agentEmail)->send(new BookingApproved($fleetBooking, $agentName));
                $mailInstance = new TourBookingApproved($tourBooking, $agentName, $booking->booking_unique_id);
                SendEmailJob::dispatch($agentEmail, $mailInstance);
                $dropOffName = null;
                $pickUpName = null;
                $is_updated = null;
                $deadlineDate = $request->input('date'); // e.g., 2025-04-14
                $deadlineTime = $request->input('time'); // e.g., 13:00
    
                $booking->deadline_date = Carbon::createFromFormat('Y-m-d H:i', $deadlineDate . ' ' . $deadlineTime)->format('Y-m-d H:i:s');
                $booking->save();
                app(TourService::class)->sendVoucherEmail(request(), $tourBooking, $is_updated);
                // Mark the email as sent
                $tourBooking->email_sent = true;
                $tourBooking->save();
            } else {
                $agentInfo = User::where('id', $tourBooking->user_id)->first(['email', 'first_name']);
                $agentEmail = $agentInfo->email;
                $agentName = $agentInfo->first_name; // Get the agent's name

                // Send the booking approval email to the agent
                // Mail::to($agentEmail)->send(new BookingApproved($fleetBooking, $agentName));
                $mailInstance = new TourBookingApproved($tourBooking, $agentName, $booking->booking_unique_id);
                SendEmailJob::dispatch($agentEmail, $mailInstance);
                $dropOffName = null;
                $pickUpName = null;
                $is_updated = null;
                $deadlineDate = $request->input('date'); // e.g., 2025-04-14
                $deadlineTime = $request->input('time'); // e.g., 13:00
    
                $booking->deadline_date = Carbon::createFromFormat('Y-m-d H:i', $deadlineDate . ' ' . $deadlineTime)->format('Y-m-d H:i:s');
                $booking->save();
                app(TourService::class)->sendVoucherEmail(request(), $tourBooking, $is_updated);
                // Mark the email as sent
                $tourBooking->email_sent = true;
                $tourBooking->save();
            }

            Toast::title('Booking Approved successfully')
                ->success()
                ->rightBottom()
                ->autoDismiss(5);
            return redirect()->back()->with('success', 'Booking Approved successfully.');
        }
    }

    public function showVoucher($id)
    {
        return $this->tourService->printVoucher($id);
    }

    public function showInvoice($id)
    {
        return $this->tourService->printInvoice($id);
    }

    public function toggleEditForm($id)
    {
        $booking = Booking::findOrFail($id);

        // Check if the form is currently displayed
        if (session()->has('toureditForm')) {
            // Toggle the session value
            session()->forget('toureditForm');
        } else {
            // Set the session value to true, showing the edit form
            session(['toureditForm' => true]);
        }

        // Redirect back to the same page to show the form
        return redirect()->back();
    }

    public function toggleTicketEditForm($id)
    {
        $booking = Booking::findOrFail($id);

        // Check if the form is currently displayed
        if (session()->has('tourticketeditForm')) {
            // Toggle the session value
            session()->forget('tourticketeditForm');
        } else {
            // Set the session value to true, showing the edit form
            session(['tourticketeditForm' => true]);
        }

        // Redirect back to the same page to show the form
        return redirect()->back();
    }

    public function toggleEditDriverForm($id)
    {
        $booking = Booking::findOrFail($id);

        // Check if the form is currently displayed
        if (session()->has('editTourDriverForm')) {
            // Toggle the session value
            session()->forget('editTourDriverForm');
        } else {
            // Set the session value to true, showing the edit form
            session(['editTourDriverForm' => true]);
        }

        // Redirect back to the same page to show the form
        return redirect()->back();
    }

    public function updateDriver($booking)
    {
        $booking = TourBooking::with('location.country')->find($booking);
        $request = request();
        $request->validate($this->driverFormValidation($request));

        $driver = Driver::firstOrCreate(
            [
                'name' => $request->input('name'),
                'car_no' => $request->input('car_no'),
                'phone_number' => $request->input('driver_phone_number'),
                'phone_code' => $request->input('driver_phone_code')
            ]
        );

        $booking->update([
            'driver_id' => $driver->id
        ]);


        $voucher = $this->tourService->sendVoucherEmail($request, $booking, 1);

    
        // Success message
        Toast::title('Driver Information Updated')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);
    
        session()->forget('editTourDriverForm');
        return redirect()->back()->with('success', 'Driver information updated successfully.');
    }

    public function driverFormValidation(Request $request): array
    {
        return [
            "name" => ['required',],
            "car_no" => ['required',],
            "driver_phone_number" => ['required',],
        ];
    }

    public function uploadTickets(Request $request, TourBooking $booking)
    {
        $request->validate([
            'ticket_files.*' => 'file|mimes:jpg,jpeg,png,pdf|max:2048',
            'reservation_id' => 'nullable|string|max:255',
        ]);
    
        $uploadedFiles = [];
        $parent_booking = Booking::with('user')
            ->where('id', $booking->booking_id)
            ->whereIn('booking_type', ['tour', 'ticket'])
            ->first();
    
        $reservationAdded = false;
    
        // Handle file uploads
        if ($request->hasFile('ticket_files')) {
            foreach ($request->file('ticket_files') as $file) {
                $path = $file->store('uploads/files', 'public');
                $uploadedFiles[] = $path;
            }
    
            $existingFiles = json_decode($booking->ticket_files, true) ?? [];
            $booking->ticket_files = json_encode(array_merge($existingFiles, $uploadedFiles));
        }
    
        // Handle reservation ID update
        if ($request->filled('reservation_id')) {
            $booking->reservation_id = $request->input('reservation_id');
            $reservationAdded = true;
        }
    
        $booking->save();
    
        // Send voucher email if reservation ID added
        if ($reservationAdded) {
            $this->tourService->sendVoucherEmail($request, $booking, 1);
        }
    
        // Send ticket upload email
        if (!empty($uploadedFiles)) {
            $mailInstance = new TourTicketUploadMail(
                $booking,
                $parent_booking->user->first_name,
                $parent_booking->booking_unique_id
            );
            SendEmailJob::dispatch($parent_booking->user->email, $mailInstance);
        }
    
        // Set specific toast messages
        if ($reservationAdded && !empty($uploadedFiles)) {
            Toast::title('Reservation ID updated and tickets uploaded successfully!')
                ->success()->rightBottom()->autoDismiss(5);
        } elseif ($reservationAdded) {
            Toast::title('Reservation ID updated successfully!')
                ->success()->rightBottom()->autoDismiss(5);
        } elseif (!empty($uploadedFiles)) {
            Toast::title('Tickets uploaded successfully!')
                ->success()->rightBottom()->autoDismiss(5);
        }
    
        session()->forget('tourticketeditForm');
        session()->save();
    
        return redirect()->back();
    }
    
    

    public function ticketDelete(Request $request, $booking_id)
    {

        $booking = TourBooking::where('id', $booking_id)->first();

        $fileToDelete = $request->input('file');

        $ticketFiles = json_decode($booking->ticket_files, true);

        if (($key = array_search($fileToDelete, $ticketFiles)) !== false) {
            // Remove the file from the array
            unset($ticketFiles[$key]);

            // Delete file from storage
            Storage::delete('public/' . $fileToDelete);

            // Update database
            $booking->ticket_files = json_encode(array_values($ticketFiles));
            $booking->save();

            Toast::title('Tickets delete successfully!')
                ->danger()
                ->rightBottom()
                ->autoDismiss(5);

            return back()->with('success', 'Ticket deleted successfully.');
        }
    }
}
