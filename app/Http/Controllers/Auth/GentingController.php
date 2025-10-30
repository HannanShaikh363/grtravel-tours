<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\GentingAddBreakFast;
use App\Models\GentingSurcharge;
use App\Models\Surcharge;
use App\Tables\GentingTableConfigurator;
use App\Services\GentingService;
use App\Services\ImportService;
use App\Models\GentingHotel;
use App\Models\Amenities;
use Illuminate\Http\Request;
use App\Models\Location;
use ProtoneMedia\Splade\Facades\Toast;
use Illuminate\Support\Facades\Redirect;
use Spatie\QueryBuilder\QueryBuilder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use App\Jobs\ProcessGentingDataJob;
use Illuminate\Support\Facades\Storage;

class GentingController extends Controller
{
    protected $gentingService, $importService;

    public function __construct(GentingService $gentingService, ImportService $importService)
    {
        $this->gentingService = $gentingService;
        $this->importService = $importService;
    }

    public function index()
    {
        $surcharges = GentingSurcharge::with('hotel')->get();
        $hotels = GentingHotel::get();
        return view('genting.index', [
            'genting' => new GentingTableConfigurator(),
            'surcharges' => $surcharges,
            'hotels' => $hotels,
        ]);
    }

    public function create()
    {
        $gentingHotel = GentingHotel::all(['id', 'hotel_name'])->pluck('hotel_name', 'id');
        return view('genting.create', ['gentingHotel' => $gentingHotel, 'amenities' => $this->getAmenities()]);
    }

    public function store(Request $request)
    {

        $request->validate($this->gentingValidationArray());
        $uploadedFiles = [];
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

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $file) {

                // Define the directory path where you want to store the file
                $directory = 'images/genting';
                // Store the file in the directory and get the stored file path
                $path = $file->store($directory, 'public');
                // $fileUrl = Storage::url($path);
                $uploadedFiles[] = $path;
            }
        }

        $gentingHotel = GentingHotel::create(
            $this->gentingHotelFormData($request, $createLocation, $uploadedFiles)
        );

        if ($request->filled(['adult', 'child'])) {
            $gentingHotel->breakfastAddition()->create([
                'adult' => $request->adult,
                'child' => $request->child,
                'currency' => $request->currency,
            ]);
        }

        Toast::title('Genting Hotel Added')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);

        return Redirect::route('genting.index')->with('status', 'Genting-created');
    }

    public function edit($id)
    {

        $genting = GentingHotel::with('location')->where('id', $id)->first();
        $facilities = json_decode($genting->facilities);

        $formData = [
            'id' => $genting->id,
            'hotel_name' => $genting->hotel_name,
            'descriptions' => $genting->descriptions,
            'location' => $genting->location,
            'amenities' => json_decode($genting->facilities),
            'others' => $genting->others,
            'property_amenities' => $facilities->amenities,
            'room_types' => $facilities->room_types,
            'room_features' => $facilities->room_features,
            'images' => $genting->images,
            'adult' => $genting->breakfastAddition->adult ?? null,
            'child' => $genting->breakfastAddition->child ?? null,

        ];
        $formData['images'] = collect(json_decode($formData['images'])) // Convert string to array
            ->map(function ($image) {
                $image = str_replace(['\\', '//'], '/', $image); // Fix slashes
                return Storage::url($image); // Generate correct URL
            })
            ->toArray();
        return view('genting.edit', ['genting' => $formData, 'amenities' => $this->getAmenities()]);
    }

    public function update($id)
    {
        $request = request();
        $request->validate($this->gentingValidationArray());
        $genting = GentingHotel::findOrFail($id);

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


        // // Retrieve existing images from request
        // $existingImages = $request->input('images_existing', []);

        // // Ensure existing images are stored as an array
        // if (!is_array($existingImages)) {
        //     $existingImages = json_decode($existingImages, true) ?? [];
        // }

        // if (!is_array($existingImages)) {
        //     $existingImages = json_decode($existingImages, true) ?? [];
        // }

        // $existingImages = array_map(function ($image) {
        //     return str_replace(asset('storage/') . '/', '', $image);
        // }, $existingImages);

        $uploadedImages = [];
        $dbImages = json_decode($genting->images, true) ?? [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $key => $image) {
                // if (!in_array($request->input('images_order')[$key] ?? '', $existingImages)) {
                $path = $image->store('images/hotels', 'public');
                // $uploadedImages[] = asset('storage/' . $path);
                $uploadedImages[] = $path;
                // }
            }
        }

        // $uploadedFiles = array_merge($existingImages, $uploadedImages);
        // dd($this->gentingHotelFormData($request,$createLocation,$uploadedImages));
        $genting->update($this->gentingHotelFormData($request, $createLocation, $uploadedImages));
        if ($request->filled(['adult', 'child'])) {
            $genting->breakfastAddition()->updateOrCreate(
                [
                    'hotel_id' => $genting->id
                ],
                [
                'adult' => $request->adult,
                'child' => $request->child,
                'currency' => $request->currency,
            ]);
        }
        Toast::title('Genting Hotel updated')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);

        return Redirect::route('genting.index')->with('status', 'Genting-updated');
    }

    public function getAmenities()
    {

        $amenities = [
            'property_amenities' => Amenities::where('type', 'property')->pluck('name'),
            'room_features' => Amenities::where('type', 'room_feature')->pluck('name'),
            'room_types' => Amenities::where('type', 'room_type')->pluck('name'),
        ];


        return $amenities;
    }

    /**
     * @param mixed $request
     * @return array
     */
    public function gentingHotelFormData($request, $createLocation, $uploadedFiles)
    {

        $facilities = [
            'amenities' => $request->property_amenities,
            'room_features' => $request->room_features,
            'room_types' => $request->room_types
        ];
        return [
            'hotel_name' => $request->hotel_name,
            'location_id' => $createLocation->id,
            'facilities' => json_encode($facilities),
            'descriptions' => $request->descriptions,
            'others' => $request->others,
            'images' => json_encode($uploadedFiles),
        ];
    }

    public function gentingValidationArray()
    {
        return [

            'hotel_name' => 'required',
            'descriptions' => 'required',
            'facilities' => 'nullable',
            'others' => 'nullable',
            'images.*' => 'file|mimes:jpg,jpeg,png|max:2048',
        ];
    }

    public function importCSV(Request $request)
    {
        try {

            $request->validate([
                'import_csv' => 'required|mimes:csv,xlsx',
            ]);

            $file = $request->file('import_csv');

            $extension = $file->getClientOriginalExtension();

            if ($extension === 'csv') {

                $reader = IOFactory::createReader('Csv');

            } else if ($extension === 'xlsx') {

                $reader = IOFactory::createReader('Xlsx');

            } else {

                return redirect()->back()->withErrors(['import_csv' => 'Unsupported file format']);
            }

            $spreadsheet = $reader->load($file->path());

            $worksheet = $spreadsheet->getActiveSheet();
            $locationArray = array_filter(array_map('trim', $this->getColumnData($spreadsheet, 'C')));
            $dbLocations = array_filter(array_map('trim', Location::pluck('name')->toArray()));
            $missingLocations = array_diff(array_unique($locationArray), $dbLocations);
            if (!empty($missingLocations)) {

                throw ValidationException::withMessages([
                    'import_csv' => ['Some locations are missing. Please create these locations first before attempting to import the file: ' . implode(', ', $missingLocations)],
                ]);
            }


            $chunkSize = 3;
            $highestRow = $worksheet->getHighestRow();

            $maxRows = 30000;
            if ($highestRow - 1 > $maxRows) {
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

                    $chunkData[] = [
                        'hotel_name' => $worksheet->getCell('A' . $row)->getValue(),
                        'description' => $worksheet->getCell('B' . $row)->getValue(),
                        'location' => $worksheet->getCell('C' . $row)->getValue(),
                        'amenities' => $worksheet->getCell('D' . $row)->getValue(),
                        'room_features' => $worksheet->getCell('E' . $row)->getValue(),
                        'room_types' => $worksheet->getCell('F' . $row)->getValue(),
                        'important_info' => $worksheet->getCell('G' . $row)->getValue(),
                        'images' => explode('||', $worksheet->getCell('H' . $row)->getValue()),
                    ];
                }

                $batch->add(new ProcessGentingDataJob($chunkData, $userId));
            }

            session()->put('importGentingLastBatchID', $batch->id);

            Toast::title('Your import task is running in the background. You will be notified once it completes!')
                ->success()
                ->rightBottom()
                ->autoDismiss(5);
            return Redirect::route('genting.index')->with('status', 'CSV Imported Successfully!');
        } catch (\Exception $e) {

            Toast::title('An error occurred: ' . $e->getMessage())
                ->danger()
                ->rightBottom()
                ->autoDismiss(5);
            Log::error('Import task failed: ' . $e->getMessage());

            return Redirect::route('genting.index')->with('status', 'Error: ' . $e->getMessage());
        }
    }

    public function getColumnData(Spreadsheet $worksheet, $columnLetter)
    {

        $highestRow = $worksheet->getActiveSheet()->getHighestRow();

        $range = $columnLetter . '2:' . $columnLetter . $highestRow;

        $columnData = $worksheet->getActiveSheet()->rangeToArray(
            $range,
            null,   // Use null for empty cells
            true,   // Calculate formulas
            true,   // Preserve cell formatting
            false   // Flat array
        );

        return array_column($columnData, 0);
    }

    public function getBatchProgress()
    {
        // $batchId = session('importGentingLastBatchID');
        return $this->importService->getBatchProgress('importGentingLastBatchID');

    }

}
