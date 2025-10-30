<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Surcharge;
use App\Tables\GentingRatesTableConfigurator;
use App\Services\GentingService;
use App\Services\ImportService;
use App\Models\GentingRate;
use App\Models\GentingPackage;
use App\Models\GentingHotel;
use App\Models\CurrencyRate;
use ProtoneMedia\Splade\Facades\Toast;
use Illuminate\Support\Facades\Redirect;
use Spatie\QueryBuilder\QueryBuilder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use App\Jobs\ProcessGentingRatesDataJob;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;

class GentingRatesController extends Controller
{

    protected $gentingService, $importService;

    public function __construct(GentingService $gentingService, ImportService $importService)
    {
        $this->gentingService = $gentingService;
        $this->importService = $importService;
    }

    public function index()
    {
        return view('gentingRates.index', [
            'genting' => new GentingRatesTableConfigurator(),
        ]);
    }



    public function create()
    {
        $genting_packages = GentingPackage::pluck('package', 'id');
        $genting_hotels = GentingHotel::pluck('hotel_name', 'id');
        $currency = CurrencyRate::pluck('target_currency', 'id');
        return view('gentingRates.create',[
            'genting_packages' => $genting_packages, 
            'genting_hotels'=>$genting_hotels,
            'currency'=>$currency
        ]);
    }

    public function store(Request $request)
    {

        $request->validate($this->gentingValidationArray());
        $uploadedFiles = [];

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $file) {

                $directory = 'images/genting/rooms';
                $path = $file->store($directory, 'public');
                $uploadedFiles[] = $path;
            }
        }

        GentingRate::create($this->gentingHotelRateFormData($request,$uploadedFiles));
        Toast::title('Genting Hotel Added')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);

        return Redirect::route('genting.rates.index')->with('status', 'Genting-Rate-Created');
    }

    public function edit($id)
    {

        $genting =  GentingRate::with('gentingHotel')->where('id', $id)->first();
        $genting_packages = GentingPackage::pluck('package', 'id');
        $genting_hotels = GentingHotel::pluck('hotel_name', 'id');
        $currency = CurrencyRate::pluck('target_currency', 'id');
        
        $formData = [
            'id' => $genting->id,
            'hotel_name' => $genting->hotel_name,
            'room_type' => $genting->room_type,
            'price' => $genting->price,
            'currency' => $genting->currency,
            'entitlements' => implode(',',json_decode($genting->entitlements)),
            'bed_count' => $genting->bed_count,
            'room_capacity' => $genting->room_capacity,
            'effective_date' => $genting->effective_date,
            'expiry_date' => $genting->expiry_date,
            'genting_package_id' => $genting->genting_package_id,
            'genting_hotel_id' => $genting->genting_hotel_id,
            'images' => $genting->images
        ];
        $formData['images'] = collect(json_decode($formData['images'])) // Convert string to array
            ->map(function ($image) {
                $image = str_replace(['\\', '//'], '/', $image); // Fix slashes
                return Storage::url($image); // Generate correct URL
            })
            ->toArray();
        return view('gentingRates.edit', [
            'genting' => $formData, 
            'genting_packages' => $genting_packages, 
            'genting_hotels'=>$genting_hotels,
            'currency'=>$currency
        ]);
    }

    public function update($id)
    {
        $request = request();
        $request->validate($this->gentingValidationArray());
        $genting = GentingRate::findOrFail($id);

        $uploadedImages = [];
        $dbImages = json_decode($genting->images, true) ?? [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $key => $image) {
                // if (!in_array($request->input('images_order')[$key] ?? '', $existingImages)) {
                    $path = $image->store('images/genting/rooms', 'public');
                    // $uploadedImages[] = asset('storage/' . $path);
                    $uploadedImages[] = $path;
                // }
            }
        }

        // dd($request->file('images'), $uploadedImages);

        // $uploadedFiles = array_merge($existingImages, $uploadedImages);
        // dd($this->gentingHotelFormData($request,$createLocation,$uploadedImages));
        $genting->update($this->gentingHotelRateFormData($request, $uploadedImages));
        // dd($genting);
        Toast::title('Genting Hotel Rate updated')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);

        return Redirect::route('genting.rates.index')->with('status', 'Genting-updated');
    }

    public function gentingHotelRateFormData($request, $uploadedFiles)
    {

        return [
            'room_type' => $request->room_type,
            'price' => $request->price,
            'currency' => $request->currency,
            'entitlements' => json_encode(explode(',',$request->entitlements)),
            'bed_count' => $request->bed_count,
            'room_capacity'=>$request->room_capacity,
            'effective_date' => $request->effective_date,
            'expiry_date' => $request->expiry_date,
            'genting_package_id' => $request->genting_package_id,
            'genting_hotel_id' => $request->genting_hotel_id,
            'images' => json_encode($uploadedFiles),
        ];
    }

    public function gentingValidationArray()
    {
        return [
            
            'room_type' => 'required',
            'price' => 'required',
            'currency' => 'required',
            'bed_count' => 'required',
            'room_capacity' => 'required',
            'genting_package_id' => 'required',
            'genting_hotel_id' => 'required',
            'entitlements' => 'nullable',
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
            // $dbLocations = array_filter(array_map('trim', Location::pluck('name')->toArray()));
            // $missingLocations = array_diff(array_unique($locationArray), $dbLocations);
            // if (!empty($missingLocations)) {

            //     throw ValidationException::withMessages([
            //         'import_csv' => ['Some locations are missing. Please create these locations first before attempting to import the file: ' . implode(', ', $missingLocations)],
            //     ]);
            // }


            $chunkSize = 10;
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
                        'hotel_name'        => $worksheet->getCell('A' . $row)->getValue(),
                        'package'           => $worksheet->getCell('B' . $row)->getValue(),
                        'room_type'         => $worksheet->getCell('C' . $row)->getValue(),
                        'price'             => $worksheet->getCell('D' . $row)->getValue(),
                        'currency'          => $worksheet->getCell('E' . $row)->getValue(),
                        'entitlements'      => $worksheet->getCell('F' . $row)->getValue(),
                        'bed_count'         => $worksheet->getCell('G' . $row)->getValue(),
                        'room_capacity'     => $worksheet->getCell('H' . $row)->getValue(),
                        'effective_date'    => $worksheet->getCell('I' . $row)->getValue(),
                        'expiry_date'       => $worksheet->getCell('J' . $row)->getValue(),
                        'images'            => explode('||',$worksheet->getCell('K' . $row)->getValue()),
                    ];
                }

                $batch->add(new ProcessGentingRatesDataJob($chunkData, $userId));
            }

            session()->put('importGentingRatesLastBatchID', $batch->id);

            Toast::title('Your import task is running in the background. You will be notified once it completes!')
                ->success()
                ->rightBottom()
                ->autoDismiss(5);
            return Redirect::route('genting.rates.index')->with('status', 'CSV Imported Successfully!');
        } catch (\Exception $e) {
            
            Toast::title('An error occurred: ' . $e->getMessage())
                ->danger()  
                ->rightBottom()
                ->autoDismiss(5);
            Log::error('Import task failed: ' . $e->getMessage());

            return Redirect::route('genting.rates.index')->with('status', 'Error: ' . $e->getMessage());
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
        return $this->importService->getBatchProgress('importGentingRatesLastBatchID');
    }
}
