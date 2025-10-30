<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\TourDestination;
use App\Models\City;
use App\Models\Country;
use App\Models\Location;
use App\Jobs\ProcessTourDestinationDataJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use ProtoneMedia\Splade\Facades\Toast;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\QueryBuilder\QueryBuilder;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use App\Tables\TourDestinationTableConfigurator;

class TourDestinationController extends Controller
{
    public function index()
    {
        return view('tourDestination.index', [
            'tourDestination' => new TourDestinationTableConfigurator(),

        ]);
    }

    public function create()
    {

        $countries = Country::all(['id', 'name'])->pluck('name', 'id');
        $tourDestinations = TourDestination::all(['id', 'name'])->pluck('name', 'id');
        return view('tourDestination.create', ['tourDestinations' => $tourDestinations]);
    }

    public function store(Request $request)
    {

        $request->validate($this->tourValidationArray());
        // Check if from_location and to_location have the required keys
        if (!isset($request->location['latitude']) || !isset($request->location['longitude'])) {
            return
                Toast::title('Please Enter Correct Location')
                    ->danger()
                    ->rightBottom()
                    ->autoDismiss(5);
        }

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
                'user_id' => auth()->id()
            ]
        );


        // dd($this->tourRateFormData($request));
        TourDestination::create($this->tourDestinationFormData($request, $createLocation));
        Toast::title('Tour Created')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);

        return Redirect::route('tour.destination.index')->with('status', 'Tour-created');
    }

    /**
     * @param mixed $request
     * @return array
     */
    public function tourDestinationFormData($request, $createLocation)
    {
        if(!is_null($request->closing_dates)){
            $closing_dates = explode(' to ', $request->closing_dates);
        } else{
            $closing_dates = [null, null];
        }
        return [
            'name' => $request->name,
            'location_id' => $createLocation->id,
            'ticket_currency' => $request->ticket_currency,
            'description' => $request->description,
            'highlights' => $request->highlights,
            'important_info' => $request->important_info,
            'adult' => $request->adult,
            'child' => $request->child,
            'hours' => $request->hours,
            'closing_day' => $request->closing_day,
            'closing_start_date' => $closing_dates[0],
            'closing_end_date' => $closing_dates[1] ?? $closing_dates[0],
            'on_request' => $request->on_request,
            'sharing' => $request->sharing,
            'ticket_title' => $request->ticket_title,
        ];
    }

    public function edit($id)
    {

        $tourDestination = TourDestination::where('id', $id)->with(['location'])->first();
        if (!is_null($tourDestination->closing_start_date) && !is_null($tourDestination->closing_end_date)) {
            $tourDestination->closing_dates = implode(' to ', [$tourDestination->closing_start_date, $tourDestination->closing_end_date]);
        }
        if(!is_null($tourDestination->closing_day)){
            $tourDestination->closing_day = json_decode( $tourDestination->closing_day, true);
        }
        // $countries = Country::all(['id', 'name'])->pluck('name', 'id');
        return view('tourDestination.edit', ['tourDestination' => $tourDestination]);
    }

    public function update($id)
    {
        $request = request();
        // dd($request);
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
        $tour = TourDestination::findOrFail($id);

        $tour->update($this->tourDestinationFormData($request, $createLocation));
        Toast::title('Tour Destination updated')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);

        return Redirect::route('tour.destination.index')->with('status', 'Tour-updated');
    }

    public function tourValidationArray()
    {
        return [

            'name' => 'required',
            'description' => 'required',
            'highlights' => 'required',
            'important_info' => 'required',
            'hours' => 'required'
        ];
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
            $locationArray = array_filter(array_map('trim', $this->getColumnData($spreadsheet, 'A')));
            $dbLocations = array_filter(array_map('trim', Location::pluck('name')->toArray()));
            $missingLocations = array_diff(array_unique($locationArray), $dbLocations);
            if (!empty($missingLocations)) {

                throw ValidationException::withMessages([
                    'import_csv' => ['Some locations are missing. Please create these locations first before attempting to import the file: ' . implode(', ', $missingLocations)],
                ]);
            }

            // Process rows in chunks
            $chunkSize = 100;
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
                        'currency' => $worksheet->getCell('D' . $row)->getValue(),
                        'ticket_title' => $worksheet->getCell('E' . $row)->getValue(),
                        'adult' => $worksheet->getCell('F' . $row)->getValue(),
                        'child' => $worksheet->getCell('G' . $row)->getValue(),
                        'description' => $worksheet->getCell('H' . $row)->getValue(),
                        'highlights' => $worksheet->getCell('I' . $row)->getValue(),
                        'important_info' => $worksheet->getCell('J' . $row)->getValue(),
                        'images' => $worksheet->getCell('K' . $row)->getValue(),
                        'closing_day' => $worksheet->getCell('L' . $row)->getValue(),
                        'time_slots' => $worksheet->getCell('M' . $row)->getValue(),
                        'on_request' => $worksheet->getCell('N' . $row)->getValue(),
                    ];
                }

                // Process the chunk of data

                $batch->add(new ProcessTourDestinationDataJob($chunkData, $userId));

            }

            session()->put('importTourDestinationLastBatchID', $batch->id);

            Toast::title('Your import task is running in the background. You will be notified once it completes!')
                ->success()
                ->rightBottom()
                ->autoDismiss(5);
            return Redirect::route('tour.destination.index')->with('status', 'CSV Imported Successfully!');
        } catch (\Exception $e) {
            // Catch any exception that occurs
            Toast::title('An error occurred: ' . $e->getMessage())
                ->danger()  // or .error() based on your Toast package
                ->rightBottom()
                ->autoDismiss(5);
            Log::error('Import task failed: ' . $e->getMessage());

            return Redirect::route('tour.destination.index')->with('status', 'Error: ' . $e->getMessage());
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

    public function getBatchProgress()
    {
        // return response()->json(['status' => 'success', 'message' => 'Batch progress route is working']);

        $batchId = session('importTourDestinationLastBatchID');
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


}
