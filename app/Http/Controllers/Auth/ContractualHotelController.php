<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\ContractualHotelService;
use App\Models\Location;
use App\Models\CurrencyRate;
use App\Models\ContractualHotel;
use App\Models\ContractualHotelRate;
use App\Models\HotelSurcharge;
use ProtoneMedia\Splade\Facades\Toast;
use Illuminate\Support\Facades\Redirect;
use App\Tables\HotelRateTableConfigurator;
use App\Models\Country;
use App\Models\City;
use App\Tables\ContractualHotelTableConfigurator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use App\Jobs\ProcessContractualHotelDataJob;
use Illuminate\Validation\ValidationException;
use App\Jobs\ProcessContractualHotelRatesJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

class ContractualHotelController extends Controller
{
    protected $ContractualHotelService;
  
    public function __construct(ContractualHotelService $ContractualHotelService)
    {
        $this->ContractualHotelService = $ContractualHotelService;
    }
    // public function index()
    // {   
    //     return view('contractualHotel.index');
    // }
     public function index()
    {
      
        $hotels = ContractualHotel::get();
        $surcharges = HotelSurcharge::with('hotel')->get();
        return view('contractualHotel.index', [
            'genting' => new ContractualHotelTableConfigurator(),
            'hotels' => $hotels,
            'surcharges' => $surcharges,
        ]);
    }
    public function create()
    {
         $countries = Country::all(['id', 'name'])->pluck('name', 'id');
        return view('contractualHotel.create', [
            'countries' => $countries,
            'location' => [],
        ]);
    }
    public function store(Request $request)
    {
        $request->validate($this->hotelValidationArray());

        $uploadedFiles = [];

         if ($request->hasFile('images')) {
            foreach ($request->file('images') as $file) {

                // Define the directory path where you want to store the file
                $directory = 'images/hotel';
                // Store the file in the directory and get the stored file path
                $path = $file->store($directory, 'public');
                // $fileUrl = Storage::url($path);
                $uploadedFiles[] = $path;
            }
        }

        $Hotel = ContractualHotel::create(
            $this->HotelFormData($request, $uploadedFiles)
        );

        Toast::title('Contractual Hotel Added')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);

        return Redirect::route('contractual_hotel.index')
            ->with('status', 'Hotel-created');
    }

    public function edit($id)
    {

        $ContractualHotel = ContractualHotel::with('cityRelation')->where('id', $id)->first();
         $countries = Country::all(['id', 'name'])->pluck('name', 'id');


        $formData = [
            'id' => $ContractualHotel->id,
            'hotel_name' => $ContractualHotel->hotel_name,
            'description' => $ContractualHotel->description,
            'location' => $ContractualHotel->cityRelation,
            'property_amenities' => $ContractualHotel->property_amenities,
            'room_features' => $ContractualHotel->room_features,
            'room_types' => $ContractualHotel->room_types,
            'important_info' => $ContractualHotel->important_info,
            'extra_bed_adult' => $ContractualHotel->extra_bed_adult,
            'extra_bed_child' => $ContractualHotel->extra_bed_child,
            'images' => $ContractualHotel->images,
            'currency' => $ContractualHotel->currency,
            'country_id' => $ContractualHotel->country_id,
            'city_id' => $ContractualHotel->city_id

        ];
        $formData['images'] = collect(json_decode($formData['images'])) // Convert string to array
            ->map(function ($image) {
                $image = str_replace(['\\', '//'], '/', $image); // Fix slashes
                return Storage::url($image); // Generate correct URL
            })
            ->toArray();
        return view('contractualHotel.edit', ['hotel' => $formData,'countries'=>$countries]);
    }
    public function hotelValidationArray($id = null)
    {
        return [
        'hotel_name' => [
            'required',
            Rule::unique('contractual_hotels', 'hotel_name')
                ->ignore($id)
                ->where(fn($query) => $query->where('city_id', request('city_id'))),
        ],
            'descriptions' => 'nullable',
            'property_amenities' => 'nullable',
            'room_features' => 'nullable',
            'room_types' => 'nullable',
            'important_info' => 'nullable',
            'extra_bed_adult' => 'required',
            'extra_bed_child' => 'required',
            'currency' => 'required',
            'images.*' => 'file|mimes:jpg,jpeg,png|max:2048',
        ];
    }

    public function HotelFormData($request, $uploadedFiles)
    {
        return [
            'hotel_name' => $request->hotel_name,
            'country_id' => $request->country_id,
            'city_id' => $request->city_id,
            'property_amenities' => $request->property_amenities,
            'description' => $request->description,
            'room_features' => $request->room_features,
            'room_types' => $request->room_types,
            'important_info' => $request->important_info,
            'extra_bed_adult' => $request->extra_bed_adult,
            'extra_bed_child' => $request->extra_bed_child,
            'currency' => $request->currency,
            'images' => json_encode($uploadedFiles),
        ];
    }
    public function rates()
    {
        return view('contractualRates.index', [
            'hotels' => new HotelRateTableConfigurator(),
        ]);
    }
       public function create_rate()
    {
     
        $contractual_hotels = ContractualHotel::pluck('hotel_name', 'id');
        return view('contractualRates.create',[
          
            'contractual_hotels'=>$contractual_hotels
        ]);
    }
    public function store_rate(Request $request){
        $request->validate($this->rateValidationArray());
        $uploadedFiles = [];

         if ($request->hasFile('images')) {
            foreach ($request->file('images') as $file) {

                // Define the directory path where you want to store the file
                $directory = 'images/hotel/rates';
                // Store the file in the directory and get the stored file path
                $path = $file->store($directory, 'public');
                // $fileUrl = Storage::url($path);
                $uploadedFiles[] = $path;
            }
        }


        // echo "<pre>";print_r($uploadedFiles);die();
        ContractualHotelRate::create($this->contractualHotelRateFormData($request,$uploadedFiles));
        Toast::title('Contractual Hotel Rate Added')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);

        return Redirect::route('contractual_hotel_rates.index')->with('status', 'Contractual-Rates-Created');
    }

    public function rateValidationArray($id = null)
    {
        return [
            'hotel_name' => [
                'required',
                'exists:contractual_hotels,id',
            ],
            'room_type' => [
                'required',
                Rule::unique('contractual_hotel_rates')
                    ->ignore($id) // ignore current record if editing
                    ->where(function ($query) {
                        return $query->where('hotel_id', request('hotel_name'))
                                     ->where('room_type', request('room_type'))
                                     ->where('room_capacity', request('room_capacity'));
                    }),
            ],
            'weekdays_price' => 'required',
            'weekend_price' => 'required',
            'currency' => 'required',
            'entitlements' => 'required',
            'no_of_beds' => 'required',
            'room_capacity' => 'required',
            'effective_date' => 'required|date',
            'expiry_date' => 'required|date|after_or_equal:effective_date',
            'images.*' => 'file|mimes:jpg,jpeg,png|max:2048',
        ];
    }

     public function contractualHotelRateFormData($request, $uploadedFiles)
    {

        return [
            'hotel_id' => $request->hotel_name,
            'room_type' => $request->room_type,
            'weekdays_price' => $request->weekdays_price,
            'weekend_price' => $request->weekend_price,
            'currency' => $request->currency,
            'entitlements'=>$request->entitlements,
            'no_of_beds' => $request->no_of_beds,
            'room_capacity' => $request->room_capacity,
            'effective_date' => $request->effective_date,
            'expiry_date' => $request->expiry_date,
            'images' => json_encode($uploadedFiles),
        ];
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
     public function update($id)
    {
        $request = request();
        $request->validate($this->hotelValidationArray($id));

        $hotel = ContractualHotel::findOrFail($id);

        // Decode old images
        $dbImages = json_decode($hotel->images, true) ?? [];


        foreach ($dbImages as $oldImage) {
            Storage::disk('public')->delete($oldImage);
        }

       if ($request->hasFile('images')) {
            foreach ($request->file('images') as $key => $image) {
                // if (!in_array($request->input('images_order')[$key] ?? '', $existingImages)) {
                $path = $image->store('images/hotels', 'public');
                // $uploadedImages[] = asset('storage/' . $path);
                $uploadedImages[] = $path;
                // }
            }
        }
        // echo "<pre>";print_r($uploadedImages);die();
        // Update DB
        $hotel->update($this->HotelFormData($request, $uploadedImages));

        Toast::title('Contractual Hotel updated')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);

        return Redirect::route('contractual_hotel.index')
            ->with('status', 'Hotel-updated');
    }



    public function edit_rate($id)
    {

        $rates =  ContractualHotelRate::with('contractualHotel')->where('id', $id)->first();
        $contractual_hotels = ContractualHotel::pluck('hotel_name', 'id');
        $currency = CurrencyRate::pluck('target_currency', 'id');
        // echo "<pre>";print_r($rates->images);die();
        
        $formData = [
            'id' => $rates->id,
            'hotel_name' => $rates->hotel_id,
            'room_type' => $rates->room_type,
            'weekdays_price' => $rates->weekdays_price,
            'weekend_price' => $rates->weekend_price,
            'currency' => $rates->currency,
            'entitlements' => $rates->entitlements,
            'no_of_beds' => $rates->no_of_beds,
            'room_capacity' => $rates->room_capacity,
            'effective_date' => $rates->effective_date,
            'expiry_date' => $rates->expiry_date,
            'images' => $rates->images
        ];
        $formData['images'] = collect(json_decode($formData['images'])) // Convert string to array
            ->map(function ($image) {
                $image = str_replace(['\\', '//'], '/', $image); // Fix slashes
                return Storage::url($image); // Generate correct URL
            })
            ->toArray();
            // echo "<pre>";print_r($formData);die();
        return view('contractualRates.edit', [
            'rates' => $formData, 
           
            'contractual_hotels'=>$contractual_hotels,
            'currency'=>$currency
        ]);
    }

   public function update_rate(Request $request, $id)
    {
        $rate = ContractualHotelRate::findOrFail($id);
        $request->validate($this->rateValidationArray($id));

        

        // 1. Delete all existing images from storage
       

        // 2. Upload new images
        $dbImages = json_decode($rate->images, true) ?? [];

        foreach ($dbImages as $oldImage) {
            Storage::disk('public')->delete($oldImage);
        }
      
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $key => $image) {
                // if (!in_array($request->input('images_order')[$key] ?? '', $existingImages)) {
                $path = $image->store('images/hotels/rates', 'public');
                // $uploadedImages[] = asset('storage/' . $path);
                $uploadedImages[] = $path;
                // }
            }
        }

        // 3. Update DB with new images only
        $rate->update($this->contractualHotelRateFormData($request, $uploadedImages));

        Toast::title('Contractual Hotel Rate Updated')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);

        return Redirect::route('contractual_hotel_rates.index')
            ->with('status', 'Contractual-Rates-Updated');
    }


    public function importCSV(Request $request)
    {
        try {
            // echo "<pre>";print_r($request->all());die();
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
            $Countryarray = array_filter(array_map('trim', $this->getColumnData($spreadsheet, 'C')));
            $dbCountries = array_filter(array_map('trim', Country::pluck('name')->toArray()));
            $missingCountries = array_diff(array_unique($Countryarray), $dbCountries);
            if (!empty($missingCountries)) {

                throw ValidationException::withMessages([
                    'import_csv' => ['Some countries are missing. Please create these countries first before attempting to import the file: ' . implode(', ', $missingCountries)],
                ]);
            }
            $cityArray = array_filter(array_map('trim', $this->getColumnData($spreadsheet, 'D')));

            $dbCities = array_filter(array_map('trim', City::pluck('name')->toArray()));
            $missingCities = array_diff(array_unique($cityArray), $dbCities);
            if (!empty($missingCities)) {

                throw ValidationException::withMessages([
                    'import_csv' => ['Some cities are missing. Please create these cities first before attempting to import the file: ' . implode(', ', $missingCities)],
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
                        'country' => $worksheet->getCell('C' . $row)->getValue(),
                        'city' => $worksheet->getCell('D' . $row)->getValue(),
                        'property_amenities' => $worksheet->getCell('E' . $row)->getValue(),
                        'room_features' => $worksheet->getCell('F' . $row)->getValue(),
                        'room_types' => $worksheet->getCell('G' . $row)->getValue(),
                        'important_info' => $worksheet->getCell('H' . $row)->getValue(),
                        'extra_bed_adult' => $worksheet->getCell('I' . $row)->getValue(),
                        'extra_bed_child' => $worksheet->getCell('J' . $row)->getValue(),
                        'currency' => $worksheet->getCell('K' . $row)->getValue(),
                        'images' => explode('||', $worksheet->getCell('L' . $row)->getValue()),
                    ];
                }

                $batch->add(new ProcessContractualHotelDataJob($chunkData, $userId));
            }

            session()->put('importContractualHotelLastBatchID', $batch->id);

            Toast::title('Your import task is running in the background. You will be notified once it completes!')
                ->success()
                ->rightBottom()
                ->autoDismiss(5);
            return Redirect::route('contractual_hotel.index')->with('status', 'CSV Imported Successfully!');
        } catch (\Exception $e) {

            Toast::title('An error occurred: ' . $e->getMessage())
                ->danger()
                ->rightBottom()
                ->autoDismiss(5);
            Log::error('Import task failed: ' . $e->getMessage());

            return Redirect::route('contractual_hotel.index')->with('status', 'Error: ' . $e->getMessage());
        }
    }
    
      public function importRatesCSV(Request $request)
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
                        'room_type'         => $worksheet->getCell('B' . $row)->getValue(),
                        'weekdays_price'    => $worksheet->getCell('C' . $row)->getValue(),
                        'weekend_price'     => $worksheet->getCell('D' . $row)->getValue(),
                        'currency'          => $worksheet->getCell('E' . $row)->getValue(),
                        'entitlements' => implode(',', array_map('trim', explode('||', $worksheet->getCell('F' . $row)->getValue() ?? ''))),
                        'no_of_beds'        => $worksheet->getCell('G' . $row)->getValue(),
                        'room_capacity'     => $worksheet->getCell('H' . $row)->getValue(),
                        'effective_date'    => $worksheet->getCell('I' . $row)->getValue(),
                        'expiry_date'       => $worksheet->getCell('J' . $row)->getValue(),
                        'images'            => explode('||',$worksheet->getCell('K' . $row)->getValue()),
                    ];
                }
                // log::info($chunkData);

                $batch->add(new ProcessContractualHotelRatesJob($chunkData, $userId));
            }

            session()->put('importHotelRatesLastBatchID', $batch->id);

            Toast::title('Your import task is running in the background. You will be notified once it completes!')
                ->success()
                ->rightBottom()
                ->autoDismiss(5);
            return Redirect::route('contractual_hotel_rates.index')->with('status', 'CSV Imported Successfully!');
        } catch (\Exception $e) {
            
            Toast::title('An error occurred: ' . $e->getMessage())
                ->danger()  
                ->rightBottom()
                ->autoDismiss(5);
            Log::error('Import task failed: ' . $e->getMessage());

            return Redirect::route('contractual_hotel_rates.index')->with('status', 'Error: ' . $e->getMessage());
        }
    }

      public function store_surcharge(Request $request)
    {
        // echo "<pre>";print_r($request->all());die();
        $request->validate([
            'hotel_id' => 'required|exists:contractual_hotels,id',
            'surcharges' => 'required|array|min:1',
            'surcharges.*.title' => 'required|string',
            'surcharges.*.amount' => 'required|numeric',
            'surcharges.*.minimum_nights' => 'required|numeric',
            'surcharges.*.validity_type' => 'required|in:date_range,in_days',
            'surcharges.*.value_type' => 'required|in:amount,percentage',
            'surcharges.*.currency' => 'required|string',
            'surcharges.*.surcharge_type' => 'required|in:discount,surcharge',
        ]);

        $surchargesToInsert = [];

        foreach ($request->surcharges as $surcharge) {
            $surchargesToInsert[] = [
                'hotel_id'        => $request->hotel_id,
                'title'           => $surcharge['title'],
                'type'            => $surcharge['surcharge_type'], // discount or surcharge
                'minimum_nights'  => $surcharge['minimum_nights'],
                'validity_type'   => $surcharge['validity_type'],  // date_range or in_days
                'start_date'      => $surcharge['validity_type'] === 'date_range' ? $surcharge['start_date'] : null,
                'end_date'        => $surcharge['validity_type'] === 'date_range' ? $surcharge['end_date'] : null,
                'fixed_days'            => $surcharge['validity_type'] === 'in_days' ? $surcharge['fixed_days'] : null,
                'not_applicable_start'  => $surcharge['not_applicable_start'] ?? null,
                'not_applicable_end'    => $surcharge['not_applicable_end'] ?? null,
                'amount_type'     => $surcharge['value_type'],     // amount or percentage
                'value'           => $surcharge['amount'],
                'currency'        => $surcharge['currency'],
                
                'created_at'      => now(),
                'updated_at'      => now(),
            ];
        }

        HotelSurcharge::insert($surchargesToInsert);

        

        return redirect()->route('contractual_hotel.index')->with('success', 'Surcharge added successfully.');
    }

    public function delete_surcharge($id)
    {
        // Find the surcharge by ID
        $surcharge = HotelSurcharge::findOrFail($id);

        // Delete the surcharge
        $surcharge->delete();

        // Return a response or redirect
        Toast::title('Surcharge deleted successfully')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);

        // Redirect back with a success message
        return redirect()->route('contractual_hotel.index')->with('status', 'surcharge-deleted');
    }
    

}
