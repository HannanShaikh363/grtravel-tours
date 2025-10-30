<?php

namespace App\Http\Controllers\Agent;

use App\Models\AgentAddCreditLimit;
use App\Models\ChartOfAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\Booking;
use App\Models\VoucherDetail;
use App\Helpers\ExportHelper;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\AccountService;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;

class AccountsController extends Controller
{
    protected $accountService;

    public function __construct(AccountService $accountService)
    {
        $this->accountService = $accountService;
    }

    public function index(Request $request)
    {
        $staff = $this->accountService->getStaff($request->user());
        $totalBookings = $this->accountService->getTotalBookings($request->user());
        // Get total booking amount for different filters
        $allTimeAmount = $this->accountService->getTotalBookingAmount($request->user(), 'all');
        $monthlyAmount = $this->accountService->getTotalBookingAmount($request->user(), 'monthly');
        $yearlyAmount = $this->accountService->getTotalBookingAmount($request->user(), 'yearly');
        $totalBookingAmount = array_sum($allTimeAmount);
        $bookingType = $this->accountService->getBookingTypesCount($request->user());
        //per type
        $monthlyAmountType = $this->accountService->getTotalBookingAmountType($request->user(), 'monthly');
        $yearlyAmountType = $this->accountService->getTotalBookingAmountType($request->user(), 'yearly');

        $canListBooking = Gate::allows('list booking');

        return view('web.agent.accounts', compact('canListBooking', 'staff', 'totalBookings', 'totalBookingAmount', 'allTimeAmount', 'monthlyAmount', 'yearlyAmount', 'bookingType', 'monthlyAmountType', 'yearlyAmountType'));
    }

    public function allBookings(Request $request)
    {
        $user = auth()->user();

        // Retrieve search inputs
        $bookingId = $request->input('booking_unique_id');
        $agentId = $request->input('agent_id');
        $amount = $request->input('amount');
        $bookingDate = $request->input('booking_date');
        $serviceDate = $request->input('service_date');
        $bookingStatus = $request->input('booking_status');
        $bookingType = $request->input('booking_type');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        // Retrieve all admin agent codes
        $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();

        // Restrict staff access
        if ($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('list booking')) {
            abort(403, 'This action is unauthorized.');
        }

        // Initialize query
        $bookings = Booking::query()
            ->leftJoin('users as agent', 'bookings.agent_id', '=', 'agent.id') // Join with agents
            ->select('bookings.*', 'agent.agent_code') // Select necessary columns
            ->when($user->type === 'agent', function ($query) use ($user) {
                // Include agent and their staff's bookings
                $staffIds = User::where('type', 'staff')->where('agent_code', $user->agent_code)->pluck('id');
                return $query->where(function ($subQuery) use ($user, $staffIds) {
                    $subQuery->where('bookings.agent_id', $user->id)
                        ->orWhereIn('bookings.agent_id', $staffIds);
                });
            })
            ->when($user->type === 'staff' && !in_array($user->agent_code, $adminCodes), function ($query) use ($user) {
                return $query->where('bookings.agent_id', $user->id);
            })
            ->when($bookingId, function ($query, $bookingId) {
                return $query->where('bookings.booking_unique_id', 'like', "%{$bookingId}%");
            })
            ->when($agentId, function ($query, $agentId) {
                return $query->where('bookings.agent_id', $agentId);
            })
            ->when($amount, function ($query, $amount) {
                return $query->where('bookings.amount', $amount);
            })
            ->when($bookingStatus, function ($query, $bookingStatus) {
                return $query->where('bookings.booking_status', $bookingStatus);
            })
            ->when($bookingType, function ($query, $bookingType) {
                return $query->where('bookings.booking_type', $bookingType);
            })
            ->when($startDate && $endDate, function ($query) use ($startDate, $endDate) {
                return $query->whereBetween('bookings.booking_date', [
                    \Carbon\Carbon::parse($startDate)->startOfDay(),
                    \Carbon\Carbon::parse($endDate)->endOfDay(),
                ]);
            })

            ->with(['user']) // Load relationship
            ->orderByDesc('created_at')
            ->paginate(10)
            ->appends($request->all()); // Retain query filters in pagination

        // Return the view with results
        return view('web.agent.partials.agent_all_bookings', compact('bookings'));
    }


    public function exportBookings(Request $request)
    {
        // Get your data
        $data = Booking::where('user_id', $request->user()->id)
            ->select('booking_unique_id', 'agent_id', 'amount', 'currency', 'booking_date', 'service_date', 'deadline_date', 'booking_type', 'booking_status')
            ->get();
        $data = $data->transform(function ($item) {
            // Replace agent_id with agent_code
            $item->agent_id = $item->agent ? $item->agent->agent_code : null;
            unset($item->agent);  // Optionally remove the agent relationship
            return $item;
        });
        $headings = ['ID', 'AgentRef', 'Amount', 'Currency', 'BookingDate', 'ServiceDate', 'DeadlineDate', 'Type', 'Status']; // Replace with your actual headings

        // Create an instance of ExportHelper and pass the data and headings
        $export = new ExportHelper($data, $headings);

        // Return the export as a downloadable Excel file
        return Excel::download($export, 'All_Bookings.xlsx');
    }

    public function getLedgerReport(Request $request)
    {
        $user = auth()->user();
        $preferredCurrency = $user->credit_limit_currency;

        // Default to staff user's finance contact
        $accountCode = $request->get('account_code') ?? optional($user->financeContact)->account_code;

        // If the user is a staff (not an agent), and has an agent_code
        if ($user->type === 'staff' && $user->agent_code) {
            // Find the main agent with the same agent_code
            $agent = User::where('agent_code', $user->agent_code)
                ->where('type', 'agent')
                ->first();

            // Use the agent's finance contact account code if exists
            if ($agent && $agent->financeContact) {
                $accountCode = $agent->financeContact->account_code;
            }
        }
        $startDate = $request->get('start_date') ?? today()->subDays(30)->toDateString(); // Start Date Filter
        $endDate = $request->get('end_date') ?? today()->toDateString(); // End Date Filter
        // if (!$startDate || !$endDate) {
        //     $endDate = today()->toDateString(); // Today's date
        //     $startDate = today()->subDays(30)->toDateString(); // 30 days ago
        // }
        // Query to calculate Opening Balance (Transactions before the start date or today if no date range is provided)
        $openingBalanceQuery = VoucherDetail::where('account_code', $accountCode);

        if ($startDate) {
            $openingBalanceQuery->whereHas('voucher', function ($q) use ($startDate) {
                $q->whereDate('v_date', '<', $startDate);
            });
        } else {
            $openingBalanceQuery->whereHas('voucher', function ($q) {
                $q->whereDate('v_date', '<', today());
            });
        }

        $openingBalance = 0;
        foreach ($openingBalanceQuery->get() as $entry) {
            $convertedDebit = $this->convertCurrency($entry->debit_forn, $entry->currency, $preferredCurrency, $entry->exchange_rate);
            $convertedCredit = $this->convertCurrency($entry->credit_forn, $entry->currency, $preferredCurrency, $entry->exchange_rate);
            $openingBalance += $convertedDebit - $convertedCredit;
        }
        // dd($convertedCredit);
        // dd($openingBalance);
        // Query for Ledger Transactions
        $query = VoucherDetail::with(['voucher'])
            ->where('account_code', $accountCode)
            ->orderBy('created_at', 'asc');

        // Apply Date Filters if provided
        if ($startDate && $endDate) {
            $query->whereHas('voucher', function ($q) use ($startDate, $endDate) {
                $q->whereBetween('v_date', [$startDate, $endDate]);
            });
        }

        // ✅ Add Pagination (10 records per page)
        $ledgerEntries = $query->get();

        // Running balance starts with opening balance
        $balance = $openingBalance;

        // Prepare Data
        $formattedEntries = collect([
            [
                'date' => '',
                'voucher_no' => '---',
                'description' => 'Opening Balance',
                'debit' => '',
                'credit' => '',
                'balance' => number_format($openingBalance, 2),
            ]
        ]);

        // Iterate over paginated results and update balance
        foreach ($ledgerEntries as $entry) {

            $debit = $this->convertCurrency($entry->debit_forn, $entry->currency, $preferredCurrency, $entry->exchange_rate);
            $credit = $this->convertCurrency($entry->credit_forn, $entry->currency, $preferredCurrency, $entry->exchange_rate);

            $balance += $debit - $credit;
            $formattedEntries->push([
                'date' => $entry->voucher->v_date,
                'voucher_no' => $entry->voucher->v_no,
                'description' => $entry->narration,
                'debit' => number_format($debit, 2),
                'credit' => number_format($credit, 2),
                'balance' => number_format($balance, 2),
            ]);
        }

        // ✅ Return JSON for Vue.js (with pagination links)
        if ($request->wantsJson()) {
            return response()->json([
                'data' => $formattedEntries,
                'pagination' => [
                    'total' => $ledgerEntries->total(),
                    'per_page' => $ledgerEntries->perPage(),
                    'current_page' => $ledgerEntries->currentPage(),
                    'last_page' => $ledgerEntries->lastPage(),
                    'next_page_url' => $ledgerEntries->nextPageUrl(),
                    'prev_page_url' => $ledgerEntries->previousPageUrl(),
                ]
            ]);
        }

        // ✅ Return Blade view with paginated data
        return view('web.accounts.leadger', compact('accountCode','formattedEntries', 'ledgerEntries', 'accountCode', 'startDate', 'endDate', 'user'));
    }

    private function convertCurrency($amount, $fromCurrency, $toCurrency, $exchangeRate = null)
    {
        // dd($fromCurrency, $toCurrency, $exchangeRate);
        if ($fromCurrency == $toCurrency) {
            return $amount;
        }

        if ($exchangeRate) {

            return $amount / $exchangeRate;
        }

        // Fetch latest exchange rate from DB or API (Implement if needed)
        return $amount; // Default case if no conversion is possible
    }


    public function exportLedger(Request $request)
    {
        $user = auth()->user();
        $preferredCurrency = $user->credit_limit_currency;

        // Default to staff user's finance contact
        $accountCode = $request->get('account_code') ?? optional($user->financeContact)->account_code;

        // If the user is a staff (not an agent), and has an agent_code
        if ($user->type === 'staff' && $user->agent_code) {
            // Find the main agent with the same agent_code
            $agent = User::where('agent_code', $user->agent_code)
                ->where('type', 'agent')
                ->first();

            // Use the agent's finance contact account code if exists
            if ($agent && $agent->financeContact) {
                $accountCode = $agent->financeContact->account_code;
            }
        }

        $openingBalanceQuery = VoucherDetail::where('account_code', $accountCode);

        // Apply date filtering correctly
        if ($request->start_date) {
            $startDate = Carbon::parse($request->start_date)->startOfDay();
            $openingBalanceQuery->whereHas('voucher', function ($q) use ($startDate) {
                $q->whereDate('v_date', '<', $startDate);
            });
        } else {
            $openingBalanceQuery->whereHas('voucher', function ($q) {
                $q->whereDate('v_date', '<', today());
            });
        }


        // Initialize opening balance
        $openingBalance = 0;

        // Convert transactions for opening balance
        foreach ($openingBalanceQuery->get() as $entry) {
            $convertedDebit = $this->convertCurrency($entry->debit_forn, $entry->currency, $preferredCurrency, $entry->exchange_rate);
            $convertedCredit = $this->convertCurrency($entry->credit_forn, $entry->currency, $preferredCurrency, $entry->exchange_rate);
            $openingBalance += $convertedDebit - $convertedCredit;
        }
        // dd($openingBalanceQuery->get());


        // Query for Ledger Transactions
        $data = VoucherDetail::where('account_code', $accountCode)
            ->select('created_at', 'voucher_id', 'narration', 'debit_forn', 'credit_forn', 'currency', 'exchange_rate')
            ->orderBy('created_at', 'asc')
            ->get();

        // Initialize running balance with the converted opening balance
        $runningBalance = $openingBalance;

        // Add Opening Balance row
        $formattedData = collect([
            (object) [
                'created_at' => null,
                'voucher_id' => '',
                'narration' => 'Opening Balance',
                'debit_pkr' => '',
                'credit_pkr' => '',
                'balance' => number_format($runningBalance, 2),
            ]
        ]);

        // Process transactions and update running balance
        $data->transform(function ($item) use (&$runningBalance, $preferredCurrency) {
            // Ensure `voucher` exists to avoid errors
            $voucherNo = optional($item->voucher)->v_no ?: 'N/A';

            // Convert voucher date
            $createdAt = convertToUserTimeZone(optional($item->voucher)->v_date, 'Y-m-d H:i:s');

            // Convert debit and credit using exchange rate
            $convertedDebit = $this->convertCurrency($item->debit_forn, $item->currency, $preferredCurrency, $item->exchange_rate);
            $convertedCredit = $this->convertCurrency($item->credit_forn, $item->currency, $preferredCurrency, $item->exchange_rate);

            // Update running balance
            $runningBalance += $convertedDebit - $convertedCredit;
            return (object) [
                'created_at' => $createdAt,
                'voucher_id' => $voucherNo,
                'narration' => $item->narration,
                'debit_pkr' => number_format($convertedDebit, 2),
                'credit_pkr' => number_format($convertedCredit, 2),
                'balance' => number_format($runningBalance, 2),
            ];
        });

        // Merge transactions with opening balance row
        $formattedData = $formattedData->merge($data);

        // Define Excel headings
        $headings = ['Date', 'Voucher No', 'Description', 'Debit', 'Credit', 'Balance'];

        // Add $abc to 'Debit' as 'Debit(MYR)'
        $headings[3] = 'Debit(' . $preferredCurrency . ')'; // This modifies the 'Debit' entry
        $headings[4] = 'Credit(' . $preferredCurrency . ')'; // This modifies the 'Credit' entry
        $headings[5] = 'Balance(' . $preferredCurrency . ')'; // This modifies the 'Balance' entry

        // Create an instance of ExportHelper with modified data
        $export = new ExportHelper($formattedData, $headings);

        // Return the export as a downloadable Excel file
        return Excel::download($export, 'Ledger_Report.xlsx');
    }




    public function getAccountCodes()
    {
        $accountCodes = ChartOfAccount::select('account_code', 'account_name')->orderBy('account_code')->get();

        return response()->json([
            'success' => true,
            'data' => $accountCodes
        ]);
    }

    public function creditLimitList(Request $request)
    {
        $query = AgentAddCreditLimit::where('agent_id', $request->user()->id);

        // Filter by Currency
        if ($request->filled('currency')) {
            $query = $query->where('currency', $request->input('currency'));
        }

        // Filter by Created Date Range
        if ($request->filled('created_at')) {
            $query = $query->whereDate(
                'created_at',
                $request->input('created_at')
            );
        }

        // Paginate results
        $creditLimits = $query->paginate(10);

        return view('web.agent.partials.agent_credit_limit', compact('creditLimits'));
    }


    public function exportCreditLimit(Request $request, $code)
    {
        // Get your data
        $data = AgentAddCreditLimit::where('agent_id', $request->user()->id)
            ->select('agent_id', 'amount', 'currency', 'user_id', 'created_at', 'active')
            ->get();
        $data = $data->transform(function ($item) {
            // Replace agent_id with agent_code
            $item->agent_id = $item->agent ? $item->agent->agent_code : null;
            unset($item->agent);  // Optionally remove the agent relationship

            // Concatenate first and last name for user_id
            $item->user_id = $item->user_id ? $item->user->first_name . ' ' . $item->user->last_name : null;

            // Show "Active" if active is 1
            $item->active = $item->active === 1 ? 'Active' : 'Inactive'; // Or use null if you prefer

            return $item;
        });

        $headings = ['AgentRef', 'Amount', 'Currency', 'Added By', 'Added At', 'Status']; // Replace with your actual headings

        // Create an instance of ExportHelper and pass the data and headings
        $export = new ExportHelper($data, $headings);

        // Return the export as a downloadable Excel file
        return Excel::download($export, 'creditLimitAgent_' . $code . '.xlsx');

    }
}
