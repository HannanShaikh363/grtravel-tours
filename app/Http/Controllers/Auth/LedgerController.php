<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\VoucherDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class LedgerController extends Controller
{
    public function getLedgerReport(Request $request)
    {
        $user = auth()->user();
        $preferredCurrency = 'USD';
        $accountCode = $request->get('account_code'); // Default account code if not provided
        $startDate = $request->get('start_date'); // Start Date Filter
        $endDate = $request->get('end_date'); // End Date Filter
    
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
    
        // $openingBalance = $openingBalanceQuery->sum(DB::raw('debit_pkr - credit_pkr'));

        $openingBalance = 0;
        foreach ($openingBalanceQuery->get() as $entry) {
            $convertedDebit = $this->convertCurrency($entry->debit_forn, $entry->currency, $preferredCurrency, $entry->exchange_rate);
            $convertedCredit = $this->convertCurrency($entry->credit_forn, $entry->currency, $preferredCurrency, $entry->exchange_rate);
            $openingBalance += $convertedDebit - $convertedCredit;
        }
    
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
    
        $ledgerEntries = $query->get();
        $balance = $openingBalance;
    
        // Prepare Data
        $formattedEntries = [
            [
                'date' => '',
                'voucher_no' => '---',
                'description' => 'Opening Balance',
                'debit' => '',
                'credit' => '',
                'balance' => number_format($openingBalance, 2),
            ]
        ];
    
        foreach ($ledgerEntries as $entry) {

            $debit = $this->convertCurrency($entry->debit_forn, $entry->currency, $preferredCurrency, $entry->exchange_rate);
            $credit = $this->convertCurrency($entry->credit_forn, $entry->currency, $preferredCurrency, $entry->exchange_rate);
            $balance += $debit - $credit;
    
            $formattedEntries[] = [
                'date' => $entry->voucher->v_date,
                'voucher_no' => $entry->voucher->v_no,
                'description' => $entry->narration,
                'debit' => number_format($debit, 2),
                'credit' => number_format($credit, 2),
                'balance' => number_format($balance, 2),
            ];
        }
    
        // Return JSON for Vue.js (Admin)
        if ($request->wantsJson()) {
            return response()->json($formattedEntries);
        }
    
        // Return Blade view for Agents
        return view('ledger.index', compact('formattedEntries', 'accountCode', 'startDate', 'endDate', 'user'));
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
}
