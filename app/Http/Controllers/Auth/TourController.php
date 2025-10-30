<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTourDataJob;
use App\Models\City;
use App\Models\User;
use App\Models\Country;
use App\Models\Location;
use App\Models\TourRate;
use App\Models\CancellationPolicies;
use App\Models\AgentPricingAdjustment;
use App\Models\TourDestination;
use App\Tables\RegisterTourTableConfigurator;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use ProtoneMedia\Splade\Facades\Toast;
use Spatie\QueryBuilder\QueryBuilder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Carbon\Carbon;
use App\Services\TourService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\JsonResponse;

class TourController extends Controller
{

    protected $tourService;

    public function __construct(TourService $tourService)
    {
        $this->tourService = $tourService;
    }

    public function index()
    {
        return view('tour.index', [
            'tour' => new RegisterTourTableConfigurator(),

        ]);
    }


    public function create()
    {

        $countries = Country::all(['id', 'name'])->pluck('name', 'id');
        $tourDestinations = TourDestination::all(['id', 'name'])->pluck('name', 'id');
        return view('tour.create', ['tourDestinations' => $tourDestinations]);
    }

    public function store(Request $request)
    {

        $request->validate($this->tourValidationArray());
        // dd($this->tourRateFormData($request));
        TourRate::create($this->tourRateFormData($request));
        Toast::title('Tour Created')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);

        return Redirect::route('tour.index')->with('status', 'Tour-created');
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
        $tour = TourRate::findOrFail($id);
        $tour->update($this->tourRateFormData($request));
        Toast::title('Tour updated')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);
        return Redirect::route('tour.index')->with('status', 'Tour-updated');
    }

    public function tourValidationArray()
    {
        return [

            'currency' => 'required',
            'price' => 'required',
            'package' => 'required',
            'seating_capacity' => 'required',
            'luggage_capacity' => 'required',
            'effective_date' => 'required|date|after_or_equal:today',
            'expiry_date' => 'nullable|date|after:effective_date',
        ];
    }

    /**
     * @param mixed $request
     * @return array
     */
    public function tourDestinationFormData($request, $createLocation)
    {
        return [
            'name' => $request->name,
            'location_id' => $createLocation->id,
            'ticket_currency' => $request->currency,
            'adult' => $request->adult,
            'child' => $request->child,
            'hours' => $request->hours,

        ];
    }

    public function tourRateFormData($request)
    {
        return [
            'package' => $request->package,
            'currency' => $request->currency,
            'seating_capacity' => $request->seating_capacity,
            'luggage_capacity' => $request->luggage_capacity,
            'price' => $request->price,
            'effective_date' => $request->effective_date,
            'expiry_date' => $request->expiry_date,
            'tour_destination_id' => $request->tour_destination ?? $request->tourDestination['id'],
            'sharing' => $request->sharing,
        ];
    }

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
            $chunkSize = 50;
            $highestRow = $worksheet->getHighestRow(); 

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
                        'sharing' => $worksheet->getCell('T' . $row)->getValue(),
                        'on_request'  => $worksheet->getCell('U' . $row)->getValue(),
                    ];
                }

                // Process the chunk of data

                $batch->add(new ProcessTourDataJob($chunkData, $userId));
                // ProcessChunkDataJob::dispatch($chunkData, $userId)->onQueue('data-import');

                // $this->getchunkdata($chunkData);
            }
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

    public function getBatchProgress()
    {
        // return response()->json(['status' => 'success', 'message' => 'Batch progress route is working']);

        $batchId = session('importTourLastBatchID');
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
