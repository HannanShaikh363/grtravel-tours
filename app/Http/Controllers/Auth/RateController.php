<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Country;
use App\Models\User;
use App\Models\Location;
use App\Models\Rate;
use App\Models\Surcharge;
use App\Models\Transport;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Http\Request;
use ProtoneMedia\Splade\Facades\Toast;
use App\Tables\RateTableConfigurator;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use App\Jobs\ProcessChunkDataJob;
use Illuminate\Validation\ValidationException;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;


class RateController extends Controller
{
    /**
     * Display the registration view.
     *
     * @return \Illuminate\View\View
     */
    public function create()
    {
        return view('rate.create');
    }

    /**
     * Display the registration view.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $surcharges = Surcharge::with('country')->get();

        return view('rate.index', [
            'rate' => new RateTableConfigurator(),
            'surcharges' => $surcharges,

        ]);
    }

    public function store(Request $request)
    {
        // Validate the incoming request
        $request->validate($this->locationFormValidateArray());

        // Check if from_location and to_location have the required keys
        if (!isset($request->from_location['latitude']) || !isset($request->from_location['longitude'])) {
            return Toast::title('Please Enter Correct Location')
                ->danger()
                ->rightBottom()
                ->autoDismiss(5);
        }

        if (!isset($request->to_location['latitude']) || !isset($request->to_location['longitude'])) {
            return Toast::title('Please Enter Correct Location')
                ->danger()
                ->rightBottom()
                ->autoDismiss(5);
        }

        // Get city and country for from and to locations
        list($fromLocationCountry, $fromLocationCity) = getCityAndCountry($request->from_location);
        list($toLocationCountry, $toLocationCity) = getCityAndCountry($request->to_location);

        // Create or find the from location
        $fromLocation = Location::firstOrCreate(
            [
                'city_id' => $fromLocationCity,
                'latitude' => $request->from_location['latitude'],
                'longitude' => $request->from_location['longitude'],
            ],
            [
                'name' => trim(strtolower($request->from_location['name'])),
                'country_id' => $fromLocationCountry,
                'user_id' => auth()->id(),
            ]
        );

        // Create or find the to location
        $toLocation = Location::firstOrCreate(
            [
                'city_id' => $toLocationCity,
                'latitude' => $request->to_location['latitude'],
                'longitude' => $request->to_location['longitude'],
            ],
            [
                'name' => trim(strtolower($request->to_location['name'])),
                'country_id' => $toLocationCountry,
                'user_id' => auth()->id(),
            ]
        );

        // Prepare rate data
        $rateData = $this->locationData($request, $fromLocation, $toLocation);

        // Create the rate record in the 'rates' table
        $rate = Rate::create($rateData);

        // If multiple transport_ids are provided, handle the pivot table logic
        if ($request->has('transport_id') && !empty($request->transport_id)) {
            $transportIds = is_array($request->transport_id) ? $request->transport_id : [$request->transport_id];

            // Save transport_ids in the pivot table (rate_transport)
            $rate->transport()->attach($transportIds);

            // Save transport_ids as a comma-separated string in the 'rates' table
            $rate->update(['transport_id' => implode(',', $transportIds)]);
        }

        // Handle child locations (if necessary)
        $this->checkChildLocationsForSomeEnterLocations($toLocation, $rate, $fromLocation, $request);

        // Success message
        Toast::title('Rate Created')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);

        return Redirect::route('rate.index')->with('status', 'rate-created');
    }




    public function show(User $user)
    {
        $user->toArray();
        exit;
        //return view('job.job', ['job' => $job]);
    }

    public function edit($id)
    {

        $rate = Rate::where('id', $id)->with('toLocation', 'fromLocation')->first();
        // $countries = Country::all(['id', 'name'])->pluck('name', 'id');
        return view('rate.edit', ['rate' => $rate]);
    }


    public function update(Request $request, Rate $rate)
    {
        // Validate the incoming request
        $request->validate($this->locationFormValidateArray());

        // Check if from_location and to_location have the required keys
        if (!isset($request->from_location['latitude']) || !isset($request->from_location['longitude'])) {
            return Toast::title('Please Enter Correct Location')
                ->danger()
                ->rightBottom()
                ->autoDismiss(5);
        }

        if (!isset($request->to_location['latitude']) || !isset($request->to_location['longitude'])) {
            return Toast::title('Please Enter Correct Location')
                ->danger()
                ->rightBottom()
                ->autoDismiss(5);
        }

        // Get city and country for from and to locations
        list($fromLocationCountry, $fromLocationCity) = getCityAndCountry($request->from_location);
        list($toLocationCountry, $toLocationCity) = getCityAndCountry($request->to_location);

        // Create or find the from location
        $fromLocation = Location::firstOrCreate(
            [
                'city_id' => $fromLocationCity,
                'latitude' => $request->from_location['latitude'],
                'longitude' => $request->from_location['longitude'],
            ],
            [
                'name' => strtolower($request->from_location['name']),
                'country_id' => $fromLocationCountry,
                'user_id' => auth()->id(),
            ]
        );

        // Create or find the to location
        $toLocation = Location::firstOrCreate(
            [
                'city_id' => $toLocationCity,
                'latitude' => $request->to_location['latitude'],
                'longitude' => $request->to_location['longitude'],
            ],
            [
                'name' => strtolower($request->to_location['name']),
                'country_id' => $toLocationCountry,
                'user_id' => auth()->id(),
            ]
        );

        // Prepare rate data
        $rateData = $this->locationData($request, $fromLocation, $toLocation);

        // Update the rate record in the 'rates' table
        $rate->update($rateData);
        // Handle the pivot table logic for transport IDs
        $transportIds = $request->has('transport_id') && !empty($request->transport_id)
            ? (is_array($request->transport_id) ? $request->transport_id : [$request->transport_id])
            : [];

        // Sync transport IDs in the pivot table (rate_transport)
        $rate->transport()->sync($transportIds);

        // Save transport IDs as a comma-separated string in the 'rates' table
        $rate->update(['transport_id' => implode(',', $transportIds)]);

        // Handle child locations (if necessary)
        $this->checkChildLocationsForSomeEnterLocations($toLocation, $rate, $fromLocation, $request);

        // Success message
        Toast::title('Rate Updated')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);

        return Redirect::route('rate.index')->with('status', 'rate-updated');
    }




    public function destroy(User $user)
    {
        $user->toArray();
        exit();
        //return view('job.job', ['job' => $job]);
    }


    /**
     * @return array
     */

    public function locationFormValidateArray(): array
    {
        return [
            "transport_id" => ['nullable'],
            "vehicle_seating_capacity" => ['nullable'],
            "vehicle_luggage_capacity" => ['nullable'],
            "rate" => ['required', 'numeric', 'min:0'],
            "currency" => ['required', 'string', 'max:255'],
            "package" => ['required', 'string', 'max:255'],
            "name" => ['required', 'string', 'max:255'],
            'hours' => 'required',
            'effective_date' => 'required|date|after_or_equal:today',
            'expiry_date' => 'nullable|date|after:effective_date',
        ];
    }


    /**
     * @param mixed $request
     * @return array
     */
    public function locationData(mixed $request, $fromLocation, $toLocation)
    {

        $transportIds = $request->transport_id; // Assuming this comes as an array
        $transportIdString = is_array($transportIds) ? implode(',', $transportIds) : null;

        return [
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'transport_id' => $transportIdString, // Store comma-separated string
            'vehicle_seating_capacity' => $request->vehicle_seating_capacity ?? null,
            'vehicle_luggage_capacity' => $request->vehicle_luggage_capacity ?? null,
            'rate' => $request->rate,
            'package' => $request->package,
            'name' => $request->name,
            'currency' => $request->currency,
            'effective_date' => $request->effective_date,
            'expiry_date' => $request->expiry_date,
            'rate_type' => $request->rate_type ? $request->rate_type : 'airport_transfer',
            'route_type' => $request->route_type ? $request->route_type : 'one_way',
            'time_remarks' => $request->time_remarks,
            'remarks' => $request->remarks,
            'hours' => $request->hours,
            'user_id' => auth()->id(),
        ];
    }


    public function listRate(Request $request)
    {

        $rates = Rate::all(['id', 'name', 'rate']);
        return response()->json($rates);
    }

    public function totalRate(int $id): JsonResponse
    {
        // Find the rate by its ID
        $rate = Rate::find($id);

        // Check if the rate exists
        if (!$rate) {
            return response()->json(['message' => 'Rate not found'], 404);
        }

        // Return the rate details as JSON
        return response()->json([
            'id' => $rate->id,
            'rate' => $rate->rate
        ]);
    }

    // public function getCityAndCountry($location)
    // {
    //     // Try to find the country
    //     if (isset($location['country_id'])) {
    //         // Look for the country using the provided country_id
    //         $country = Country::find($location['country_id']);
    //     } else {
    //         // If no country_id, search by country name
    //         $country = Country::where('name', $location['country'] ?? null)->first();
    //     }

    //     // If a country was found, proceed with finding the city
    //     if ($country) {
    //         // Try to find the city
    //         if (isset($location['city_id'])) {
    //             // Look for the city using the provided city_id
    //             $city = City::find($location['city_id']);
    //         } else {
    //             // If no city_id, search by city name within the found country
    //             $city = City::where('country_id', $country->id)
    //                 ->where('name', $location['city'] ?? null)
    //                 ->first();
    //         }

    //         // Return the IDs of the country and city (if city is found)
    //         return [$country->id, $city ? $city->id : null];
    //     }

    //     // If no country found, return null for both
    //     return [null, null];
    // }


    public function importCSV(Request $request)
    {
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
        $fromLocationArray = array_filter(array_map('trim', $this->getColumnData($spreadsheet,'B')));
        $toLocationArray = array_filter(array_map('trim', $this->getColumnData($spreadsheet,'C')));
        $locationsArray = array_filter(array_map('trim', array_merge($fromLocationArray,$toLocationArray ))); 
        $dbLocations = array_filter(array_map('trim', Location::pluck('name')->toArray()));
        $missingLocations = array_diff(array_unique($locationsArray), $dbLocations);
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
                    'name'                   => $worksheet->getCell('A' . $row)->getValue(),
                    'from_location_id'       => $worksheet->getCell('B' . $row)->getValue(),
                    'to_location_id'         => $worksheet->getCell('C' . $row)->getValue(),
                    'vehicle_make'         => $worksheet->getCell('D' . $row)->getValue(),
                    'transport_id'           => $worksheet->getCell('E' . $row)->getValue(),
                    'vehicle_seating_capacity' => $worksheet->getCell('F' . $row)->getValue(),
                    'vehicle_luggage_capacity' => $worksheet->getCell('G' . $row)->getValue(),
                    'rate'                   => $worksheet->getCell('H' . $row)->getValue(),
                    'package'                => $worksheet->getCell('I' . $row)->getValue(),
                    'currency'               => $worksheet->getCell('J' . $row)->getValue(),
                    'effective_date'         => $worksheet->getCell('K' . $row)->getValue(),
                    'expiry_date'            => $worksheet->getCell('L' . $row)->getValue(),
                    'route_type'             => $worksheet->getCell('M' . $row)->getValue(),
                    'time_remarks'             => $worksheet->getCell('N' . $row)->getValue(),
                    'remarks'             => $worksheet->getCell('O' . $row)->getValue(),
                    'hours'             => $worksheet->getCell('P' . $row)->getValue(),
                    'rate_type'             => $worksheet->getCell('Q' . $row)->getValue(),
                ];
            }

            // Process the chunk of data
            
            $batch->add(new ProcessChunkDataJob($chunkData, $userId));
            // ProcessChunkDataJob::dispatch($chunkData, $userId)->onQueue('data-import');
            
            // $this->getchunkdata($chunkData);
        }
        // dd($batch->id);
        session()->put('importRatesLastBatchID',$batch->id);
        // dd(session('importRatesLastBatchID'));
        Toast::title('Your import task is running in the background. You will be notified once it completes!')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);

        return Redirect::route('rate.index')->with('status', 'CSV Imported Successfully!');
    }

    public function getchunkdata($chunkData)
    {
        // Define the required fields for validation
        $requiredFields = [
            'from_location_id',
            'to_location_id',
            'transport_id',
            'vehicle_seating_capacity',
            'vehicle_luggage_capacity',
            'rate',
            'package',
            'name',
            'currency',
            'effective_date',
            'expiry_date',
            'route_type',
            'vehicle_make',
            'time_remarks',
            'remarks',
            'hours',
            'rate_type',
        ];
        $userId = auth()->id();

        ProcessChunkDataJob::dispatch($chunkData, $userId)->onQueue('data-import');
    }

    public function getBatchProgress()
    {
        // return response()->json(['status' => 'success', 'message' => 'Batch progress route is working']);
        
        $batchId = session('importRatesLastBatchID');
        // dd('rrrr',$batchId);
        if (!$batchId) {
            return response()->json(['error' => 'No batch found'], 404);
        }

        $batch = Bus::findBatch($batchId);
        if (!$batch) {
            return response()->json(['error' => 'Batch not found'], 404);
        }
        
        return response()->json([
            'total_jobs' => $batch->totalJobs,
            'pending_jobs' => $batch->pendingJobs,
            'failed_jobs' => $batch->failedJobs,
            'processed_jobs' => $batch->processedJobs(),
            'progress' => $batch->progress(),
            'finished' => $batch->finished(),
        ]);
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



    private function convertDate($dateValue)
    {
        // Check if dateValue is an object and is a valid date
        if ($dateValue instanceof \PhpOffice\PhpSpreadsheet\Cell\Coordinate) {
            return Date::excelToDateTimeObject($dateValue)->format('Y-m-d');
        }

        // Handle numeric value that represents an Excel date
        if (is_numeric($dateValue)) {
            return Date::excelToDateTimeObject($dateValue)->format('Y-m-d');
        }

        // If it's already a string, attempt to format it
        $date = \DateTime::createFromFormat('Y-m-d', $dateValue);
        if ($date) {
            return $date->format('Y-m-d');
        }

        // If no valid date, return null or handle as needed
        return null;
    }
    /**
     * @param $toLocation
     * @param $rate
     * @param $fromLocation
     * @param Request $request
     * @return void
     */
    public function checkChildLocationsForSomeEnterLocations($toLocation, $rate, $fromLocation, Request $request): void
    {
        $kualaLumpurLocationIds = [23, 24];
        if (in_array($toLocation->id, $kualaLumpurLocationIds)) {
            $childLocationIds = [16, 17, 18, 19, 20, 21, 22, 23];

            foreach ($childLocationIds as $childLocationId) {
                $childLocationName = Location::find($childLocationId)->name;
                $dynamicName = $rate->name . " -> {$fromLocation->name} to {$childLocationName}";
                $childData = [
                    'package' => $request->package,
                    'name' => $dynamicName,
                    'from_location_id' => $fromLocation->id,
                    'to_location_id' => $childLocationId,
                    'transport_id' => $request->transport_id,
                    'vehicle_seating_capacity' => $request->vehicle_seating_capacity,
                    'vehicle_luggage_capacity' => $request->vehicle_luggage_capacity,
                    'rate' => $request->rate,
                    'currency' => $request->currency,
                    'effective_date' => $request->effective_date,
                    'expiry_date' => $request->expiry_date,
                    'child_id' => $childLocationId,
                ];
                Rate::create($childData);
            }
        }
    }

    
}
