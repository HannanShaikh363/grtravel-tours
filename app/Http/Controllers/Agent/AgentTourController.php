<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTourDataJob;
use App\Jobs\SendEmailJob;
use App\Mail\BookingApprovalPending;
use App\Mail\BookingApprovalPendingAdmin;
use App\Models\AgentPricingAdjustment;
use App\Models\Booking;
use App\Mail\BookingCancel;
use App\Models\CancellationPolicies;
use App\Models\City;
use App\Models\Configuration;
use App\Models\Country;
use App\Models\CurrencyRate;
use App\Models\FleetBooking;
use App\Models\Location;
use App\Models\MeetingPoint;
use App\Models\Surcharge;
use App\Models\TourRate;
use App\Models\User;
use App\Models\Tour;
use App\Models\TourBooking;
use App\Models\TourDestination;
use App\Models\TransferHotel;
use App\Services\BookingService;
use App\Services\CurrencyService;
use App\Tables\RegisterTourTableConfigurator;
use Carbon\Carbon;
use GuzzleHttp\Promise\Create;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use ProtoneMedia\Splade\Facades\Toast;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Illuminate\Support\Facades\Gate;
use App\Services\TourService;
use Illuminate\Support\Facades\Validator;

class AgentTourController extends Controller
{

    protected $tourService, $bookingService;

    public function __construct(TourService $tourService, BookingService $bookingService)
    {
        $this->tourService = $tourService;
        $this->bookingService = $bookingService;
    }
    public function index()
    {
        $packages = TourRate::select('package')
            ->where('currency', 'MYR')
            ->orderByDesc('created_at')
            ->limit(3)
            ->get();


        $tours = collect();


        foreach ($packages as $package) {
            $rate = TourRate::where('package', $package->package)
                ->latest()
                ->first();

            $tours->push($rate);
        }

        return view('web.tour.tour_dashboard', ['tours' => $tours]);
    }

    public function create()
    {

        $countries = Country::all(['id', 'name'])->pluck('name', 'id');
        return view('tour.create');
    }

    public function store(Request $request)
    {
        try {
            // dd($request->all());
            $validator = Validator::make(
                $request->all(),
                $this->tourService->tourFormValidationArray($request),
                [
                    'nationality_id.required' => 'Please select a nationality.',
                ]
            );
            $user = auth()->user();
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(), // Send validation errors as a JSON object
                ], 422);
            }

            $tourBooking = $this->tourService->storeBooking($request);
            $tourBooking = json_decode($tourBooking->getContent(), true);
            if (!empty($tourBooking) && isset($tourBooking['success']) && !$tourBooking['success']) {

                Toast::title($tourBooking['error'])
                    ->danger()
                    ->rightBottom()
                    ->autoDismiss(5);

                // if ($request->ajax()) {
                //     return $tourBooking;
                // }

                 return response()->json([
                    'success' => false,
                    'errors' => $tourBooking['error'],
                ], 500);
            }

            if ($request->submitButton == "pay_offline") {

                Toast::title('Payment Done.')
                    ->danger()
                    ->rightBottom()
                    ->autoDismiss(5);
            }
            session()->flash('success', 'Booking successfully created!');
            $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
            if ($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('list booking')) {
                return response()->json([
                    'success' => true,
                    'message' => $tourBooking['message'],
                    'redirect_url' => $tourBooking['redirect_url']
                ]);
            }
            return response()->json([
                'success' => true,
                'message' => $tourBooking['message'],
                'redirect_url' => route('tourbookings.index')
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'An error occurred: ' . $e], 500);
        }
    }

    public function edit($id)
    {

        $tour = TourRate::where('id', $id)->with(['tourDestination', 'tourDestination.location'])->first();
        // $countries = Country::all(['id', 'name'])->pluck('name', 'id');
        return view('tour.edit', ['tour' => $tour]);
    }

    public function update($id)
    {
        $request = request();
        $request->validate($this->tourValidationArray());
        list($locationCountry, $locationCity) = getCityandCountry($request->location);
        $createLocation = Location::firstOrCreate(
            [
                'name' => $request->location['name'],
                'latitude' => $request->location['latitude'],
                'longitude' => $request->location['longitude']
            ],
            [
                'city_id' => $locationCity,
                'country_id' => $locationCountry,
                'user_id' => auth()->id(),
            ]
        );
        $tour = Tour::findOrFail($id);
        $tour->update($this->tourFormData($request, $createLocation));
        Toast::title('Tour updated')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);

        return Redirect::route('tour.index')->with('status', 'Tour-updated');
    }




    // public function getCityAndCountry($location)
    // {
    //     // Check if 'country_id' is present in the location data
    //     if (isset($location['country_id'])) {
    //         // Find the country by ID
    //         $country = Country::find($location['country_id']);
    //     } else {
    //         // Fallback to finding the country by name
    //         $country = Country::where('name', $location['country'])->first();
    //     }

    //     if ($country) {
    //         // Check if 'city_id' is present in the location data
    //         if (isset($location['city_id'])) {
    //             // Find the city by ID
    //             $city = City::find($location['city_id']);
    //         } else {
    //             // Fallback to finding the city by name and country_id
    //             $city = City::where('country_id', $country->id)
    //                 ->where('name', $location['city'])
    //                 ->first();
    //         }

    //         // If both country and city exist, return their IDs
    //         if ($city) {
    //             return [$country->id, $city->id];
    //         }
    //     }

    //     // If no match found, return [null, null] as a default value
    //     return [null, null];
    // }

    public function listTour(Request $request)
    {
        // Fetch all tours
        $tours = Tour::all(['id', 'name']);

        // Add a default empty value to the list
        $defaultEmptyValue = collect([
            ['id' => '', 'name' => 'Select a tour']
        ]);

        // Merge the default empty value with the list of tours
        $result = $defaultEmptyValue->concat($tours);

        return response()->json($result);
    }

    public function importCSV(Request $request)
    {
        try {
            // Validate the uploaded file
            $request->validate([
                'import_csv' => 'required|mimes:csv,xlsx',
            ]);

            // Get the file
            $file = $request->file('import_csv');

            // Identify the file extension and choose the correct reader
            $extension = $file->getClientOriginalExtension();

            if ($extension === 'csv') {
                // Handle CSV file
                $reader = IOFactory::createReader('Csv');
            } else if ($extension === 'xlsx') {
                // Handle XLSX file
                $reader = IOFactory::createReader('Xlsx');
            } else {
                return redirect()->back()->withErrors(['import_csv' => 'Unsupported file format']);
            }

            // Load the file into a Spreadsheet object
            $spreadsheet = $reader->load($file->path());

            // Get the first worksheet
            $worksheet = $spreadsheet->getActiveSheet();
            // $fromLocationArray = array_filter(array_map('trim', $this->getColumnData($spreadsheet, 'B')));
            $locationArray = array_filter(array_map('trim', $this->getColumnData($spreadsheet, 'A')));
            // $toLocationArray = array_filter(array_map('trim', $this->getColumnData($spreadsheet, 'C')));
            // $toLocationArray = array_filter(array_map('trim', $this->getColumnData($spreadsheet, 'C')));
            // $locationsArray = array_filter(array_map('trim', array_merge($fromLocationArray, $toLocationArray)));
            $dbLocations = array_filter(array_map('trim', Location::pluck('name')->toArray()));
            $missingLocations = array_diff(array_unique($locationArray), $dbLocations);
            if (!empty($missingLocations)) {
                // Throw a validation exception with a custom error message
                throw ValidationException::withMessages([
                    'import_csv' => ['Some locations are missing. Please create these locations first before attempting to import the file: ' . implode(', ', $missingLocations)],
                ]);
            }

            // Process rows in chunks
            $chunkSize = 1000;
            $highestRow = $worksheet->getHighestRow(); // Total number of rows

            $maxRows = 30000; // Define your maximum row limit
            if ($highestRow - 1 > $maxRows) { // Subtract 1 to account for the header row
                Toast::title("The uploaded file contains too many rows. Maximum allowed is {$maxRows}.")
                    ->danger()
                    ->rightBottom()
                    ->autoDismiss(5);
                return back()->withErrors("The uploaded file contains too many rows. Maximum allowed is {$maxRows}.");
            }

            $userId = auth()->id();
            $batch = Bus::batch([])->dispatch();
            for ($startRow = 2; $startRow <= $highestRow; $startRow += $chunkSize) {
                $chunkData = [];

                for ($row = $startRow; $row < $startRow + $chunkSize && $row <= $highestRow; $row++) {
                    // Using cell references instead of getCellByColumnAndRow
                    $chunkData[] = [
                        'location_id' => $worksheet->getCell('A' . $row)->getValue(),
                        'name' => $worksheet->getCell('B' . $row)->getValue(),
                        'hours' => $worksheet->getCell('C' . $row)->getValue(),
                        'package' => $worksheet->getCell('D' . $row)->getValue(),
                        'seating_capacity' => $worksheet->getCell('E' . $row)->getValue(),
                        'luggage_capacity' => $worksheet->getCell('F' . $row)->getValue(),
                        'price' => $worksheet->getCell('G' . $row)->getValue(),
                        'currency' => $worksheet->getCell('H' . $row)->getValue(),
                        'effective_date' => $worksheet->getCell('I' . $row)->getValue(),
                        'expiry_date' => $worksheet->getCell('J' . $row)->getValue(),
                        'adult' => $worksheet->getCell('K' . $row)->getValue(),
                        'child' => $worksheet->getCell('L' . $row)->getValue(),
                        'remarks' => $worksheet->getCell('M' . $row)->getValue(),
                        'description' => $worksheet->getCell('N' . $row)->getValue(),
                        'highlights' => $worksheet->getCell('O' . $row)->getValue(),
                        'important_info' => $worksheet->getCell('P' . $row)->getValue(),
                        'images' => $worksheet->getCell('Q' . $row)->getValue(),
                        'closing_day' => $worksheet->getCell('R' . $row)->getValue(),
                        'time_slots' => $worksheet->getCell('S' . $row)->getValue(),
                    ];
                }
                $closing_day = trim($worksheet->getCell('R' . $row)->getValue());
                log::info('Closing Day: ' . $closing_day);

                // Process the chunk of data

                $batch->add(new ProcessTourDataJob($chunkData, $userId));
                // ProcessChunkDataJob::dispatch($chunkData, $userId)->onQueue('data-import');

                // $this->getchunkdata($chunkData);
            }
            // dd($batch->id);
            session()->put('importTourLastBatchID', $batch->id);
            // dd(session('importRatesLastBatchID'));
            Toast::title('Your import task is running in the background. You will be notified once it completes!')
                ->success()
                ->rightBottom()
                ->autoDismiss(5);
            return Redirect::route('tour.index')->with('status', 'CSV Imported Successfully!');
        } catch (\Exception $e) {
            // Catch any exception that occurs
            Toast::title('An error occurred: ' . $e->getMessage())
                ->danger()  // or .error() based on your Toast package
                ->rightBottom()
                ->autoDismiss(5);
            Log::error('Import task failed: ' . $e->getMessage());

            return Redirect::route('tour.index')->with('status', 'Error: ' . $e->getMessage());
        }
    }

    public function getColumnData(Spreadsheet $worksheet, $columnLetter)
    {
        // Get the highest row in the sheet
        $highestRow = $worksheet->getActiveSheet()->getHighestRow();

        // Define the range for the column (e.g., "A1:A100")
        $range = $columnLetter . '2:' . $columnLetter . $highestRow;

        // Fetch the column data in one go
        $columnData = $worksheet->getActiveSheet()->rangeToArray(
            $range,
            null,   // Use null for empty cells
            true,   // Calculate formulas
            true,   // Preserve cell formatting
            false   // Flat array
        );

        // Flatten the array (optional, as rangeToArray returns nested arrays by default)
        return array_column($columnData, 0);
    }

    public function fetchlist(Request $request)
    {

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
            $parameters = $this->tourService->extractRequestParameters($request);
            $query = $this->tourService->buildQuery($parameters);
            $results = $this->tourService->applyFiltersAndPaginate($query, $parameters);
            $adjustedRates = $this->tourService->adjustTours($results, $parameters);
            return $this->prepareResponse($request, $adjustedRates, $parameters);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()]);
        }
    }

    // private function extractRequestParameters(Request $request)
    // {
    //     return [
    //         'dropoff_address' => $request->input('dropoff_address'),
    //         'pickup_address' => $request->input('pickup_address'),
    //         'travel_date' => $request->input('travel_date'),
    //         'tour_time' => $request->input('tour_time') ?? '00:00',
    //         'travel_location' => $request->input('travel_location'),
    //         'dropoff_location' => $request->input('dropoff_location'),
    //         'adult_capacity' => $request->input('adult_capacity'),
    //         'child_capacity' => $request->input('child_capacity'),
    //         'luggage_capacity' => $request->input('luggage_capacity'),
    //         'price' => $request->input('price'),
    //         'destination' => $request->input('destination'),
    //         'hour' => $request->input('hour'),
    //         'package' => $request->input('package'),
    //         'currency' => $request->input('currency'),
    //     ];
    // }

    // private function buildQuery(array $parameters)
    // {
    //     $query = Tour::query();

    //     $query->addSelect('tours.*');

    //     // Base price and price per adult/child calculation
    //     if (!empty($parameters['adult_capacity']) || !empty($parameters['child_capacity'])) {
    //         $adultCount = $parameters['adult_capacity'] ?? 0;
    //         $childCount = $parameters['child_capacity'] ?? 0;

    //         // Calculate total price: Base price + (adult price * adult count) + (child price * child count)
    //         $query->selectRaw(
    //             "tours.price + (tours.adult * ?) + (tours.child * ?) AS total_price",
    //             [$adultCount, $childCount]
    //         );

    //         // Optionally filter tours by budget if provided
    //         if (!empty($parameters['budget'])) {
    //             $query->having('total_price', '<=', $parameters['budget']);
    //         }

    //         // Sort tours by total price in ascending order
    //         $query->orderBy('total_price', 'asc');
    //     }

    //     // Filter by travel location
    //     if (!empty($parameters['travel_location'])) {
    //         $location = Location::where('name', $parameters['travel_location']['name'])->first();
    //         if ($location) {
    //             $query->where('location_id', $location->id);
    //         }
    //     }

    //     // Filter by seating capacity
    //     if (!empty($parameters['adult_capacity']) && !empty($parameters['child_capacity'])) {
    //         $totalCapacity = $parameters['adult_capacity'] + $parameters['child_capacity'];
    //         $query->where('seating_capacity', '>=', $totalCapacity);
    //     } elseif (!empty($parameters['adult_capacity'])) {
    //         $query->where('seating_capacity', '>=', $parameters['adult_capacity']);
    //     } elseif (!empty($parameters['child_capacity'])) {
    //         $query->where('seating_capacity', '>=', $parameters['child_capacity']);
    //     }

    //     // Filter by luggage capacity (if applicable)
    //     if (!empty($parameters['luggage_capacity'])) {
    //         $query->where('luggage_capacity', '>=', $parameters['luggage_capacity']);
    //     }

    //     return $query;
    // }


    // private function applyFiltersAndPaginate($query, array $parameters)
    // {
    //     // Filter by price range if provided
    //     if (!empty($parameters['price'])) {
    //         $priceRange = $this->extractPriceRange($parameters['price']);
    //         $query->whereBetween('price', $priceRange);
    //     }

    //     // Filter by hours if provided
    //     if (!empty($parameters['hour'])) {
    //         $query->where('hours', $parameters['hour']);
    //     }

    //     // Filter by package if provided
    //     if (!empty($parameters['package'])) {
    //         $query->whereIn('package', $parameters['package']);
    //     }

    //     // Filter by location_id if provided
    //     if (!empty($parameters['location_id'])) {
    //         $query->where('location_id', $parameters['location_id']);
    //     }

    //     // Apply sorting
    //     $query->orderBy('price', 'ASC');

    //     // Execute the query and paginate the results
    //     $results = $query->paginate(20);

    //     // Append filters to the pagination links
    //     $results->appends($parameters);

    //     return $results;
    // }


    // private function adjustRates($rates, array $parameters)
    // {
    //     $adjustmentRate = AgentPricingAdjustment::getCurrentAdjustmentRate(auth()->id());
    //     foreach ($rates as $rate) {
    //         $rate->price = $this->applyCurrencyConversion($rate->price, $rate->currency, $parameters['currency']);
    //         $rate->price = $this->tourService->applyAdjustment($rate->price, $adjustmentRate);
    //     }
    //     return $rates;
    // }

    // private function applyCurrencyConversion($rate, $currentCurrency, $targetCurrency)
    // {
    //     if ($targetCurrency) {
    //         $usdRate = CurrencyService::convertCurrencyToUsd($currentCurrency, $rate);
    //         return round(CurrencyService::convertCurrencyFromUsd($targetCurrency, $usdRate), 2);
    //     }
    //     return $rate;
    // }

    private function prepareResponse(Request $request, $adjustedRates, array $parameters)
    {
        $currency = $parameters['currency'];
        $nextPageUrl = $adjustedRates->nextPageUrl();
        $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        $canCreate = auth()->user()->type !== 'staff' ||
            in_array(auth()->user()->agent_code, $adminCodes) ||
            Gate::allows('create booking');
        // Group tours by 'tour_name' after fetching paginated results
        $user = auth()->user();
        $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        $can_create = true;
        if ($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('create tour')) {
            $can_create = false;
        }
        if ($request->ajax()) {
            return response()->json([
                'html' => view('web.tour.listTour', [
                    'showTours' => $adjustedRates,
                    'cancellationPolicy' => CancellationPolicies::getActivePolicyByType('transfer'),
                    'currency' => $currency,
                    'parameters' => $parameters,
                    'canCreate' => $canCreate,
                ])->render(),
                'next_page' => $nextPageUrl,
            ]);
        }

        return view('web.tour.search', [
            'cancellationPolicy' => CancellationPolicies::where('active', 1)->where('type', 'tour')->first(),
            'showTours' => $adjustedRates,
            'booking_date' => $parameters['travel_date'],
            'filters' => $this->tourService->getFilters($parameters, $adjustedRates),
            'currency' => $currency,
            'next_page' => $nextPageUrl,
            'parameters' => $parameters,
            'canCreate' => $canCreate,
        ]);
    }

    public function tourView(Request $request, $id, $pick_date, $pick_time, $currency, $rate, $adult_capacity, $child_capacity, $infant_capacity, $child_ages)
    {

        // Check if child_ages is "N/A", and if so, treat it as an empty array
        if ($child_ages === 'N-A') {
            $childAges = []; // No child ages
        } else {
            $childAges = explode(',', $child_ages); // Convert string back to array
        }
        // $user = auth()->user();
        // $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        // if ($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('create booking')) {
        //     abort(403, 'This action is unauthorized.');
        // }

        $data = TourRate::where('id', $id)->first();

        // Check if tour rate data exists
        if (!$data) {
            return redirect()->back()->with('error', 'Tour not found');
        }

        // Fetch the related tour destination using the tour_destination_id from the $data
        $tourDestination = TourDestination::where('id', $data->tour_destination_id)->first();

        // Check if tour destination data exists
        if (!$tourDestination) {
            return redirect()->back()->with('error', 'Tour destination not found');
        }

        // Decode the JSON time_slots from the tourDestination table
        $timeSlots = json_decode($tourDestination->time_slots, true);  // Decode JSON into an array

        // Step 3: Get the selected time slot from the URL or request
        $selectedTimeSlot = $request->input('time_slots');

        // Check if the selected time slot is available in the time_slots array
        if ($selectedTimeSlot && !in_array($selectedTimeSlot, $timeSlots)) {
            return redirect()->back()->with('error', 'Invalid time slot selected');
        }

        if ($data->sharing === 1) {
            // Multiply price per person when sharing is enabled
            $tour_price = ($data->price * $adult_capacity) + ($data->price * $child_capacity) + ($tourDestination->adult * $adult_capacity) + ($tourDestination->child * $child_capacity);
        } else {
            // Use the standard pricing method
            $tour_price = $data->price + ($tourDestination->adult * $adult_capacity) + ($tourDestination->child * $child_capacity);
        }

        $agentCode = auth()->user()->agent_code;

        $agentId = User::where('agent_code', $agentCode)
            ->where('type', 'agent')
            ->value('id');

        $adjustmentRate = AgentPricingAdjustment::getCurrentAdjustmentRate($agentId);
        $tour_price = $this->tourService->applyCurrencyConversion($tour_price, $data->currency, $currency);
        $tour_price = $this->tourService->applyAdjustment($tour_price, $adjustmentRate);
        if ($adjustmentRate && $adjustmentRate->isNotEmpty()) {
            // Filter the adjustment rates for transaction_type === 'transfer'
            $tourRates = $adjustmentRate->filter(function ($rate) {
                return $rate->transaction_type === 'tour';
            });

            foreach ($tourRates as $tourRate) {
                // Pass the individual adjustment rate object
                $tour_price = $this->tourService->applyAdjustment($tour_price, $tourRate);
            }
        }
        // Multiply the adult and child prices and add to the base price


        // Split the highlights string by hyphen "\n" and trim extra spaces from each item
        $highlights = array_filter(array_map('trim', explode('||', $tourDestination->highlights)));
        $important_info = array_filter(array_map('trim', explode('||', $tourDestination->important_info)));
        $description = $tourDestination->description;

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

        $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        $canCreate = auth()->user()->type !== 'staff' ||
            in_array(auth()->user()->agent_code, $adminCodes) ||
            Gate::allows('create booking');
        return view(
            'web.tour.tour_view',
            [
                'data' => $data,
                'tourDestination' => $tourDestination,
                'tour_date' => $pick_date,
                'pick_time' => $pick_time,
                'adult_capacity' => $adult_capacity,
                'child_capacity' => $child_capacity,
                'infant_capacity' => $infant_capacity,
                'child_ages' => $childAges,
                'currency' => $currency,
                'price' => number_format($tour_price, 2),
                'highlights' => $highlights,
                'important_info' => $important_info,
                'paragraph' => $paragraph,
                'listItems' => $listItems,
                'booking_date' => $pick_date,
                'booking_slot' => $selectedTimeSlot,
                'cancellationPolicy' => CancellationPolicies::where('active', 1)->where('type', 'tour')->first(),
                'countries' => Country::all(['id', 'name'])->pluck('name', 'id'),
                'canCreate' => $canCreate,
            ]
        );
    }

    public function tourSubmission(Request $request, $id, $pick_date, $pick_time, $currency, $rate, $adult_capacity, $child_capacity, $infant_capacity, $child_ages, $booking_slot)
    {
        $childAges = json_decode(rawurldecode($child_ages), true);
        $user = auth()->user();
        $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        if ($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('create booking')) {
            abort(403, 'This action is unauthorized.');
        }

        $data = TourRate::where('id', $id)->first();

        // Check if tour rate data exists
        if (!$data) {
            return redirect()->back()->with('error', 'Tour not found');
        }

        // Fetch the related tour destination using the tour_destination_id from the $data
        $tourDestination = TourDestination::where('id', $data->tour_destination_id)->first();

        // Check if tour destination data exists
        if (!$tourDestination) {
            return redirect()->back()->with('error', 'Tour destination not found');
        }

        // Decode the JSON time_slots from the tourDestination table
        $timeSlots = json_decode($tourDestination->time_slots, true);  // Decode JSON into an array

        // Step 3: Get the selected time slot from the URL or request
        $selectedTimeSlot = $booking_slot;

        // Check if the selected time slot is available in the time_slots array
        if ($selectedTimeSlot && !in_array($selectedTimeSlot, $timeSlots)) {
            return redirect()->back()->with('error', 'Invalid time slot selected');
        }

        if ($data->sharing === 1) {
            // Multiply price per person when sharing is enabled
            $tour_price = ($data->price * $adult_capacity) + ($data->price * $child_capacity) + ($tourDestination->adult * $adult_capacity) + ($tourDestination->child * $child_capacity);
        } else {
            // Use the standard pricing method
            $tour_price = $data->price + ($tourDestination->adult * $adult_capacity) + ($tourDestination->child * $child_capacity);
        }
        // dd($tourDestination->adult);
        $agentCode = auth()->user()->agent_code;

        $agentId = User::where('agent_code', $agentCode)
            ->where('type', 'agent')
            ->value('id');

        $adjustmentRate = AgentPricingAdjustment::getCurrentAdjustmentRate($agentId);
        $tour_price = $this->tourService->applyAdjustment($tour_price, $adjustmentRate);
        if ($adjustmentRate && $adjustmentRate->isNotEmpty()) {
            // Filter the adjustment rates for transaction_type === 'transfer'
            $tourRates = $adjustmentRate->filter(function ($rate) {
                return $rate->transaction_type === 'tour';
            });

            foreach ($tourRates as $tourRate) {
                // Pass the individual adjustment rate object
                $tour_price = $this->tourService->applyAdjustment($tour_price, $tourRate);
            }
        }
        
        $net_price = $tour_price;
        $tour_price = $this->tourService->applyCurrencyConversion($tour_price, $data->currency, $currency);
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
        
   
        return view(
            'web.tour.tour_booking',
            [
                'data' => $data,
                'tourDestination' => $tourDestination,
                'tour_date' => $pick_date,
                'pick_time' => $pick_time,
                'adult_capacity' => $adult_capacity,
                'child_capacity' => $child_capacity,
                'infant_capacity' => $infant_capacity,
                'child_ages' => $childAges,
                'currency' => $currency,
                'price' => number_format($tour_price, 2),
                'highlights' => $highlights,
                'important_info' => $important_info,
                'remainingDays' => $remainingDays,
                'is_within_24_hours' => $is_within_24_hours,
                'booking_slot' => $booking_slot,
                'cancellationPolicy' => CancellationPolicies::where('active', 1)->where('type', 'tour')->first(),
                'countries' => Country::all(['id', 'name'])->pluck('name', 'id'),
                'net_price' => $net_price,
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
        $tourName = $request->input('tour_name');
        $package = $request->input('package');
        $tour_date = $request->input('tour_date');
        $pick_time = $request->input('pickup_time');
        $location = $request->input('location');
        $type = $request->input('type');
        $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        if ($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('list booking')) {
            abort(403, 'This action is unauthorized.');
        }

        // Query the database with search and filters
        $bookings = TourBooking::query()
            ->leftJoin('bookings', 'tour_bookings.booking_id', '=', 'bookings.id') // Join with the bookings table
            ->leftJoin('tour_rates', 'tour_bookings.tour_rate_id', '=', 'tour_rates.id') // Join tours using tour_rate_id
            ->leftJoin('tour_destinations', 'tour_rates.tour_destination_id', '=', 'tour_destinations.id') // Join tour_destinations table
            ->leftJoin('locations as location', 'tour_bookings.location_id', '=', 'location.id') // Join locations for tours
            ->leftJoin('users as agent', 'tour_bookings.user_id', '=', 'agent.id') // Join booking table with users table
            ->select(
                'tour_bookings.*',
                'bookings.*', // Select columns from the bookings table
                'location.name as location_name', // Location name for tours
                'tour_destinations.name as tour_destination_name',
                'agent.agent_code' // agent_code from users table
            )
            // Filter based on user type
            ->when($user->type === 'agent', function ($query) use ($user) {
                // Include bookings for the agent and their staff
                $staffIds = User::where('type', 'staff')->where('agent_code', $user->agent_code)->pluck('id');
                return $query->where(function ($subQuery) use ($user, $staffIds) {
                    $subQuery->where('tour_bookings.user_id', $user->id)
                        ->orWhereIn('tour_bookings.user_id', $staffIds);
                });
            })
            ->when($user->type === 'staff' && !in_array($user->agent_code, $adminCodes), function ($query) use ($user) {
                return $query->where('tour_bookings.user_id', $user->id);
            })
            ->when($referenceNo, function ($query, $referenceNo) {
                return $query->where('tour_bookings.user_id', $referenceNo);
            })
            ->when($bookingId, function ($query, $bookingId) {
                return $query->where('bookings.booking_unique_id', 'like', '%' . $bookingId);
            })
            ->when($reservationType, function ($query, $reservationType) {
                if ($reservationType !== '') {
                    return $query->where('bookings.booking_status', $reservationType); // '1' or '0'
                }
            })
            ->when($tourName, function ($query, $tourName) {
                return $query->where('tour_bookings.tour_name', 'like', "%{$tourName}%");
            })
            ->when($package, function ($query, $package) {
                return $query->where('tour_bookings.package', 'like', "%{$package}%");
            })
            ->when($tour_date, function ($query, $tour_date) {
                // Ensure the input date is in 'Y-m-d' format for comparison
                return $query->whereDate('tour_bookings.tour_date', '=', $tour_date);
            })
            ->when($pick_time, function ($query, $pick_time) {
                // Ensure time is in the correct format, and compare with `pick_time` field
                return $query->whereTime('tour_bookings.pickup_time', '=', $pick_time);
            })
            ->when($location, function ($query, $location) {
                return $query->where('location.name', 'like', "%{$location}%");
            })
            ->when($type, function ($query, $type) {
                return $query->where('tour_bookings.type', 'like', "%{$type}%");
            })
            ->with(['booking', 'booking.user'])
            ->orderBy('bookings.booking_date', 'desc')
            ->paginate(10)
            ->appends($request->all()); // Retain query inputs in pagination links
        // Total bookings count
        $totalBookings = TourBooking::count();
        $offline_payment = route('tourOfflineTransaction');
        $limit = auth()->user()->getEffectiveCreditLimit();

        return view('web.tour.tourBookingList', compact('bookings', 'totalBookings', 'offline_payment', 'limit'));
    }

    public function updatePassenger(Request $request, TourBooking $booking)
    {
        try {
            // Call the service to update passenger information
            $booking = $this->tourService->updatePassenger($request, $booking);

            // If the request is AJAX, return a JSON response
            if ($request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Booking details updated successfully.',
                    'booking' => $booking
                ]);
            }

            // For non-AJAX (normal form submission), redirect back with success message
            return redirect()->route('tourBookings.details', ['id' => $booking->booking_id])
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


    public function viewDetails($id)
    {
        // Load cancellation booking policies dynamically
        $cancellation = CancellationPolicies::where('active', 1)->get();
        // Fetch the booking details by ID
        $booking = TourBooking::where('booking_id', $id)->firstOrFail();
        $nationality = Country::where('id', $booking->nationality_id)->value('name');
        $currency = $booking->currency;

        $bookingStatus = Booking::where('booking_type_id', $booking->id)
            ->whereIn('booking_type', ['tour', 'ticket']) // Fetch both tour and ticket
            ->first();

        $user = User::where('id', $booking->user_id)->first();
        $createdBy = (($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: 'Admin';

        $location = Location::where('id', $booking->location_id)->value('name');

        $countries = Country::all(['id', 'name'])->pluck('name', 'id');

        $cancellationBooking = $cancellation->filter(function ($policy) use ($bookingStatus) {
            return $policy->type == $bookingStatus->booking_type && $bookingStatus->booking_type !== 'ticket';
        });

        $targetDate = Carbon::createFromFormat('Y-m-d H:i:s', $bookingStatus->service_date); // Replace with your target date and time
        // Get the current date and time
        $currentDate = Carbon::now();
        // Calculate the difference in days
        $remainingDays = $currentDate->diffInDays($targetDate, false); // 'false' to allow negative values if targetDate is in the past
        $user = auth()->user();
        $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        $can_edit = !($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('update tour'));
        $can_delete = !($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('delete tour'));
        $cancellationPolicy = CancellationPolicies::where('active', 1)->where('type', 'tour')->first();
        $childAges = json_decode(rawurldecode($booking->child_ages), true);
        // Return the view with booking details
        return view('web.tour.agentTourBooking_details', compact(
            'booking',
            'countries',
            'nationality',
            'location',
            // 'tour_destination',
            // 'tour_rates',
            'currency',
            'createdBy',
            'bookingStatus',
            'cancellationBooking', // Pass the filtered cancellation policies
            'remainingDays',
            'can_edit',
            'can_delete',
            'childAges',
            'cancellationPolicy'
        ));
    }

    public function showVoucher($id)
    {
        return $this->tourService->printVoucher($id);
    }

    public function showInvoice($id)
    {
        return $this->tourService->printInvoice($id);
    }

    public function storeTicket(Request $request): JsonResponse
    {
        try {
            // dd($request->all());
            $validator = Validator::make(
                $request->all(),
                $this->tourService->ticketFormValidationArray($request),
                [
                    'nationality_id.required' => 'Please select a nationality.',
                ]
            );
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
                    ->rightBottom()
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
                    ->rightBottom()
                    ->autoDismiss(5);
            }
            session()->flash('success', 'Booking successfully created!');
            $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
            if ($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('list booking')) {
                return response()->json([
                    'success' => true,
                    'message' => $tourBooking['message'],
                    'redirect_url' => $tourBooking['redirect_url'],
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => $tourBooking['message'],
                'redirect_url' => route('tourbookings.index'),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'An error occurred: ' . $e], 500);
        }
    }

    public function ticketSubmission(Request $request, $id, $pick_date, $pick_time, $currency, $rate, $adult_capacity, $child_capacity, $infant_capacity, $child_ages)
    {
        // Check if child_ages is "N/A", and if so, treat it as an empty array
        if ($child_ages === 'N-A') {
            $childAges = []; // No child ages
        } else {
            $childAges = explode(',', $child_ages); // Convert string back to array
        }
        $user = auth()->user();
        $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        $can_create = true;
        if ($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('create booking')) {
            $can_create = false;
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

        // Step 3: Get the selected time slot from the URL or request
        $selectedTimeSlot = $request->input('time_slots');

        // Check if the selected time slot is available in the time_slots array
        if ($selectedTimeSlot && !in_array($selectedTimeSlot, $timeSlots)) {
            return redirect()->back()->with('error', 'Invalid time slot selected');
        }

        $agentCode = auth()->user()->agent_code;
        $agentId = User::where('agent_code', $agentCode)
            ->where('type', 'agent')
            ->value('id');
        $tour_price = $tourDestination->adult * $adult_capacity + $tourDestination->child * $child_capacity;
        $adjustmentRate = AgentPricingAdjustment::getCurrentAdjustmentRate($agentId);
        $tour_price = $this->tourService->applyCurrencyConversion($tour_price, $tourDestination->ticket_currency, $currency);
        
        // $tour_price = $this->tourService->applyAdjustment($tour_price, $adjustmentRate);
        if ($adjustmentRate && $adjustmentRate->isNotEmpty()) {
            // Filter the adjustment rates for transaction_type === 'transfer'
            $tourRates = $adjustmentRate->filter(function ($rate) {
                return $rate->transaction_type === 'tour';
            });
            
            foreach ($tourRates as $tourRate) {
                // Pass the individual adjustment rate object
                $tour_price = $this->tourService->applyAdjustment($tour_price, $tourRate);
            }
        }


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
        $is_within_48_hours = $time_diff_in_hours > 0 && $time_diff_in_hours <= 48;

        $is_on_request = $tourDestination->on_request;
        return view(
            'web.tour.tickets.tickets_booking',
            [
                'tourDestination' => $tourDestination,
                'tour_date' => $pick_date,
                'pick_time' => $pick_time,
                'adult_capacity' => $adult_capacity,
                'child_capacity' => $child_capacity,
                'infant_capacity' => $infant_capacity,
                'child_ages' => $childAges,
                'currency' => $currency,
                'price' => number_format($tour_price, 2),
                'remainingDays' => $remainingDays,
                'is_within_48_hours' => $is_within_48_hours,
                'is_on_request' => $is_on_request,
                'booking_slot' => $selectedTimeSlot,
                'can_create' => $can_create,
                'cancellationPolicy' => CancellationPolicies::where('active', 1)->where('type', 'tour')->first(),
                'countries' => Country::all(['id', 'name'])->pluck('name', 'id'),
            ]
        );
    }

    public function unapprove($id)
    {
        $tourBooking = TourBooking::findOrFail($id);

        $fromLocation = null;
        $toLocation = null;
        $isCancelByAdmin = $tourBooking->created_by_admin;

        $booking = Booking::where('booking_type_id', $tourBooking->id)->whereIn('booking_type', ['tour', 'ticket'])->first();
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
                    $fromLocation, $toLocation, $bookingDate, $location,
                    $booking->booking_type,
                    $amountRefunded
                );
                $adminEmails =  [config('mail.notify_tour'), config('mail.notify_info'), config('mail.notify_account')];
                foreach ($adminEmails as $adminEmail) {
                    SendEmailJob::dispatch($adminEmail, $admin);
                }
                $tourBooking->email_sent = true;
                $tourBooking->save();
            }
        }

        return redirect()->route('tourBookings.details', ['id' => $booking->id])
            ->with('success', 'Booking canceled successfully.');
    }
}
