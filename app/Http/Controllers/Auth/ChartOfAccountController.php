<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\ChartOfAccountService;
use App\Services\ImportService;
use App\Tables\ChartOfAccountTableConfigurator;
use ProtoneMedia\Splade\Facades\Toast;
use Illuminate\Support\Facades\Redirect;
use Spatie\QueryBuilder\QueryBuilder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Bus;
use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\Log;
use App\Jobs\ProcessChatOfAccountDataJob;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class ChartOfAccountController extends Controller
{
    protected $chartOfAccountService, $importService;

    public function __construct(ChartOfAccountService $chartOfAccountService, ImportService $importService)
    {
        $this->chartOfAccountService = $chartOfAccountService;
        $this->importService = $importService;
    }

    public function index()
    {   
        return view('chartOfAccount.index', [
            'chart_of_account' => new ChartOfAccountTableConfigurator(),
        ]);
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

            $chunkSize = 200;
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
                        'account_code' => $worksheet->getCell('A' . $row)->getValue(),
                        'account_name' => $worksheet->getCell('B' . $row)->getValue(),
                        'nature'       => $worksheet->getCell('C' . $row)->getValue(),
                        'parent_account_code' => $worksheet->getCell('D' . $row)->getValue(),
                        'level'        => $worksheet->getCell('E' . $row)->getValue(),
                        'type'         => $worksheet->getCell('F' . $row)->getValue(),
                        'currency'     => $worksheet->getCell('G' . $row)->getValue(),
                        'status'       => $worksheet->getCell('H' . $row)->getValue(),
                    ];
                }

                $batch->add(new ProcessChatOfAccountDataJob($chunkData, $userId));
            }

            session()->put('importChatOfAccountLastBatchID', $batch->id);

            Toast::title('Your import task is running in the background. You will be notified once it completes!')
                ->success()
                ->rightBottom()
                ->autoDismiss(5);
            return Redirect::route('chartOfAccount.index')->with('status', 'CSV Imported Successfully!');
        } catch (\Exception $e) {
            
            Toast::title('An error occurred: ' . $e->getMessage())
                ->danger()  
                ->rightBottom()
                ->autoDismiss(5);
            Log::error('Import task failed: ' . $e->getMessage());

            return Redirect::route('chartOfAccount.index')->with('status', 'Error: ' . $e->getMessage());
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
        return $this->importService->getBatchProgress('importChatOfAccountLastBatchID');
    
    }

    public function create()
    {

        $accounts = ChartOfAccount::where('level', 0)
            ->where('status', 1)
            ->get(['id','account_code', 'account_name'])
            ->mapWithKeys(function ($account) {
                return [$account->account_code => $account->account_code . ' || ' . $account->account_name];
            })
            ->toArray();

        // $accounts = ChartOfAccount::where('level', 0)
        //             ->select('id', DB::raw("CONCAT(account_code, ' | ', account_name) as account_details"))
        //             ->get();
        return view('chartOfAccount.create',['accounts' => $accounts, "defaultStatus" => '1','isEdit' => false]);
    }

    public function store(Request $request)
    {
        $request->validate($this->accountValidationArray());
        $parentAccount = $this->getParnetAccountDetail($request->parent_id);
        $newAccountCode = $this->generateAccountCode($parentAccount);
        ChartOfAccount::create($this->accountFormData($request, $parentAccount,$newAccountCode));
        Toast::title('Account Added')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);

        return Redirect::route('chartOfAccount.index')->with('status', 'Account Created Successfully!');
    }

    public function generateAccountCode($parentAccount)
    {
    
        $parentCode = $parentAccount->account_code; 
    
        $lastChildAccount = ChartOfAccount::where('parent_id', $parentAccount->id)
            ->where('account_code', 'LIKE', "{$parentCode}%")
            ->orderBy('account_code', 'desc')
            ->first();

        
    
        if ($lastChildAccount) {

            $lastChildCode = $lastChildAccount->account_code;
            $nextNumber = (int)substr($lastChildCode, strlen($parentCode)) + 1;
        } else {

            $nextNumber = 1;
        }
    
        return $parentCode . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
    }

    public function getParnetAccountDetail($account_code){
        $parentAccount = ChartOfAccount::where('account_code', $account_code)->first();
    
        if (!$parentAccount) {
            throw new \Exception('Parent account not found.');
        }
    
        return $parentAccount; 
    }
    

    public function accountValidationArray()
    {
        return [
            
            'parent_id' => 'required',
            'account_name' => 'required',
            'currency' => 'required',
            'status' => 'nullable',
        ];
    }

    public function accountFormData($request, $parentAccount = null, $newAccountCode = null)
    {

        return [
            'parent_id'     => $parentAccount?->id ?? $request->parent_id,
            'nature'        => $parentAccount?->nature ?? $request->nature,
            'account_name'  => $request->account_name,
            'currency'      => $request->currency,
            'status'        => $request->status,
            'level'         => $parentAccount ? 1 : ($request->level ?? 0), 
            'account_code'  => $newAccountCode ?? $request->account_code,
        ];
    }

    public function edit($id)
    {

        $account =  ChartOfAccount::with('parent')->where('id', $id)->first();
        $accounts = ChartOfAccount::where('level', 0)
            ->where('status', 1)
            ->get(['id','account_code', 'account_name'])
            ->mapWithKeys(function ($account) {
                return [$account->account_code => $account->account_code . ' || ' . $account->account_name];
            })
            ->toArray();

        
        $formData = [
            'id' => $account->id,
            'account_code' => $account->account_code,
            'account_name' => $account->account_name,
            'nature' => $account->nature,
            'parent_id'=>$account->parent->account_code,
            'level' => $account->level,
            'type' => $account->type,
            'currency' => $account->currency,
            'status' => $account->status
        ];

        return view('chartOfAccount.edit', [
            'account' => $formData, 
            'accounts' => $accounts,
            'defaultStatus' => $account->status,
            'isEdit' => true,
        ]);
    }

    public function update($id)
    {
        $request = request();
        $request->validate($this->accountValidationArray());
        $account = ChartOfAccount::with('parent')->findOrFail($id);
        $request->merge([
            'parent_id' => optional($account->parent)->id,
        ]);

        $account->update($this->accountFormData($request));

        Toast::title('Account Details updated')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);

        return Redirect::route('chartOfAccount.index')->with('status', 'Account-updated');
    }

}
