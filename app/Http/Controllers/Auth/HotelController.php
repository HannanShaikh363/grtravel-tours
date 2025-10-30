<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\User;
use App\Services\BookingService;
use App\Services\HotelService;
use App\Services\RezliveHotelService;
use App\Jobs\ProcessHotelImport;
use App\Jobs\ProcessAllTboCities;
use ProtoneMedia\Splade\FileUploads\SpladeFile;
use Carbon\Carbon;
use Illuminate\Http\Request;
use ProtoneMedia\Splade\Facades\Toast;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Crypt;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Bus;

class HotelController extends Controller
{

    protected $hotelService;
    protected $rezliveHotelService;
    protected $bookingService;
  
    public function __construct(HotelService $hotelService, RezliveHotelService $rezliveHotelService, BookingService $bookingService)
    {
        $this->hotelService = $hotelService;
        $this->rezliveHotelService = $rezliveHotelService;
        $this->bookingService = $bookingService;
    }

    public function index()
    {   
        return view('hotel.index');
    }

    public function uploadCsv(Request $request)
    {
        try {

            // $request->validate([
            //     'import_csv' => 'required|mimes:csv',
            // ]);

            $file = $request->file('import_csv');        
            // Store file with a fixed name or generate unique name
            $fileName = 'hotel_data.csv';
            Storage::putFileAs('hotels', $file, $fileName);
            // $file->move($folderPath, $fileName);

            Toast::title('File Uploaded Successfully')
                ->success()
                ->rightBottom()
                ->autoDismiss(5);
            Log::error('File Uploaded Successfully');
            return Redirect::route('hotel.index')->with('status', 'CSV uploaded Successfully!');
            
        } catch (\Exception $e) {
            
            Toast::title('An error occurred: ' . $e->getMessage())
                ->danger()  
                ->rightBottom()
                ->autoDismiss(5);
            Log::error('Upload task failed: ' . $e->getMessage());

            return Redirect::route('hotel.index')->with('status', 'Error: ' . $e->getMessage());
        }
    }

    public function uploadRezliveCsv(Request $request)
    {
        $request->validate([
            'import_hotel_csv' => 'required|string',
        ]);

        try {
            // Decrypt and validate file metadata
            $spladeMeta = unserialize(Crypt::decryptString($request->import_hotel_csv));
            
            if (!isset($spladeMeta['path'])) {
                $this->showErrorToast('File metadata is invalid - path missing');
                return back()->withErrors(['File metadata is invalid - path missing.']);
            }

            // Quick file validation
            $filePath = storage_path("splade-temporary-file-uploads/{$spladeMeta['path']}");
            if (!$this->validateImportFile($filePath)) {
                return back();
            }

            // Dispatch import job immediately
            ProcessHotelImport::dispatch($filePath, auth()->id())->onQueue('imports');
            
            $this->showSuccessToast('Hotel import started successfully. You will be notified when completed.');
            return back();

        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            Log::error('Decryption failed: ' . $e->getMessage());
            $this->showErrorToast('File decryption failed');
            return back()->withErrors(['File decryption failed. Please try again.']);
        } catch (\Exception $e) {
            Log::error('Import failed: ' . $e->getMessage());
            $this->showErrorToast('Import initialization failed');
            return back()->withErrors(['An unexpected error occurred: ' . $e->getMessage()]);
        }
    }

    protected function validateImportFile(string $filePath): bool
    {
        if (!File::exists($filePath)) {
            $this->showErrorToast('Uploaded file not found');
            return false;
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'txt'])) {
            $this->showErrorToast('Only CSV/TXT files are accepted');
            return false;
        }

        return true;
    }

    protected function showErrorToast(string $message): void
    {
        Toast::title($message)
            ->danger()
            ->rightBottom()
            ->autoDismiss(5);
    }

    protected function showSuccessToast(string $message): void
    {
        Toast::title($message)
            ->success()
            ->rightBottom()
            ->autoDismiss(5);
    }


    public function tboHotelSynced()  {
        try {
            ProcessAllTboCities::dispatch()->onQueue('imports');
            
            return response()->json([
                'success' => true,
                'message' => 'Hotel sync process started for all cities'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to start sync process',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
