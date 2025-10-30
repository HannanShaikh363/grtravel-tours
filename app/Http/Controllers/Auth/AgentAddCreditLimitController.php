<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AgentAddCreditLimit;
use App\Services\ChartOfAccountService;
use App\Models\VoucherType;
use App\Models\Voucher;
use App\Models\VoucherDetail;
use App\Models\ChartOfAccount;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use ProtoneMedia\Splade\Facades\Toast;
use App\Models\CurrencyRate;
use App\Services\CurrencyService;

class AgentAddCreditLimitController extends Controller
{
    //

    protected $chartOfAccountService;

    public function __construct(ChartOfAccountService $chartOfAccountService)
    {
        $this->chartOfAccountService = $chartOfAccountService;
    }


    public function index($type)
    {
        //        $agent_pricing_adjustments = AgentAddCreditLimit::all();
        //        return view('agent_.index', compact('agent_pricing_adjustments'));
    }


    public function create(Request $request)
    {

        $request->validate([
            'agent' => ['required', 'array'], // Ensure 'agent' is an array
            'agent.id' => ['required', 'integer'], // Validate 'id' within the 'agent'
            'agent.name' => ['required', 'string'], // Validate 'name' within the 'agent'
            'amount' => ['required', 'string']
        ], [
            'agent.array' => 'The agent field select via autocomplete.',    // Custom message for 'array'
        ]);

        $agent_pricing_adjustment = new AgentAddCreditLimit();
        $agent_pricing_adjustment->agent_id = $request->agent['id'];
        $agent_pricing_adjustment->currency = $request->currency;
        $agent_pricing_adjustment->amount = $request->amount;
        $agent_pricing_adjustment->user_id = Auth::user()->id;
        $agent_pricing_adjustment->active = 1;
        $agent_pricing_adjustment->save();

        $hasAccount = $this->chartOfAccountService->hasFinanceAccountCodes($request->agent['id']);

        if(!$hasAccount){
            // Toast::info('Assign an account to the user for the add credit');
            Toast::title('Assign an account to the user for the add credit')
            ->success()
            ->rightBottom()
            ->autoDismiss(10);
            return redirect()->back()->with('success', 'Assign an account to the user for the add credit');
        }

        $agent = User::with('financeContact')->find($request->agent['id']);
        $staffs = User::where('type', 'staff')->where('agent_code', $agent->agent_code)->get();
        // Initialize the amount variable
        $amount = 0;

        // Check if the agent's credit limit currency matches the request currency
        if ($agent->credit_limit_currency == $request->currency) {
            // Simply add the amount to the credit limit
            $agent->credit_limit += $request->amount;
            foreach($staffs as $staff){
                $staff->credit_limit += $request->amount;
                $staff->save();
            }
        }
        else {
            // Case where the agent's credit limit currency is USD
            if ($agent->credit_limit_currency == 'USD') {
                // Get the conversion rate from USD to the target currency
                $conversionRate = CurrencyRate::where('base_currency', 'USD')
                    ->where('target_currency', $request->currency)
                    ->orderby('rate_date')
                    ->first();

                if (!$conversionRate) {
                    // Handle the error (e.g., throw an exception or return an error response)
                    throw new Exception("Currency rate not found.");
                }

                $amount = $request->amount + ($conversionRate->rate * $agent->credit_limit);
            } else {
                // Get the current rate from USD to the agent's credit limit currency
                $currentRateUSto = CurrencyRate::where('base_currency', 'USD')
                    ->where('target_currency', $agent->credit_limit_currency)
                    ->orderby('rate_date')
                    ->first();

                // Get the target rate from USD to the requested currency
                $targetRateUSto = CurrencyRate::where('base_currency', 'USD')
                    ->where('target_currency', $request->currency)
                    ->orderby('rate_date')
                    ->first();

                if (!$currentRateUSto || !$targetRateUSto) {
                    // Handle the error
                    throw new Exception("Currency rate not found.");
                }

                // Convert the existing credit limit to USD and then to the target currency
                $previousAmountInUSD = ($agent->credit_limit / $currentRateUSto->rate);
                $amount = $request->amount + ($previousAmountInUSD * $targetRateUSto->rate);
            }

            // Update the agent's credit limit and currency
            $agent->credit_limit = $amount;
            $agent->credit_limit_currency = $request->currency;

            if($staffs){
            foreach ($staffs as $staff) {
                if ($staff->credit_limit_currency == 'USD') {
                    $conversionRate = CurrencyRate::where('base_currency', 'USD')
                        ->where('target_currency', $request->currency)
                        ->orderBy('rate_date', 'desc')
                        ->first();
            
                    if (!$conversionRate) {
                        throw new Exception("Currency rate not found for staff.");
                    }
            
                    $amount = $request->amount + ($conversionRate->rate * $staff->credit_limit);
                } else {
                    $currentRateUSto = CurrencyRate::where('base_currency', 'USD')
                        ->where('target_currency', $staff->credit_limit_currency)
                        ->orderBy('rate_date', 'desc')
                        ->first();
            
                    $targetRateUSto = CurrencyRate::where('base_currency', 'USD')
                        ->where('target_currency', $request->currency)
                        ->orderBy('rate_date', 'desc')
                        ->first();
            
                    if (!$currentRateUSto || !$targetRateUSto) {
                        throw new Exception("Currency rate not found for staff.");
                    }
            
                    $previousAmountInUSD = ($staff->credit_limit / $currentRateUSto->rate);
                    $amount = $request->amount + ($previousAmountInUSD * $targetRateUSto->rate);
                }
            
                // Update each staff's credit limit and currency
                $staff->credit_limit = $amount;
                $staff->credit_limit_currency = $request->currency;
                $staff->save();
            }
        }
        }

        // Save the updated agent data only once
        $agent->save();

        $voucherType = VoucherType::where('code', 'BV-R')->first();
        $voucherTypeId = $voucherType ? $voucherType->id : 1;
        $voucher = Voucher::create([
            'v_no' => Voucher::generateVoucherNumber($voucherTypeId),
            'v_date' => now(),
            'voucher_type_id' => $voucherTypeId,
            'narration' => 'Wallet balance assigned to agent, Agent Code - Name:'. $agent->agent_code .'-'.$agent->first_name.' '.$agent->last_name,
            'total_debit' => $request->amount,
            'total_credit' => $request->amount,
            'currency' => $request->currency,
            'reference_id' => ''
        ]);

        $exchange_Rate = CurrencyService::getCurrencyRate($request->currency);
        $usd_rate = CurrencyService::convertCurrencyToUsd($request->currency, $request->amount);
        $pkr_rate = round(CurrencyService::convertCurrencyFromUsd('PKR', $usd_rate), 2);

        $salesAccount = ChartOfAccount::where('account_code', $agent->financeContact->sales_account_code)->first(); // Sales Revenue
        $walletAccount = ChartOfAccount::where('account_code', $agent->financeContact->account_code)->first(); // Wallet Account
        $bankAccount = ChartOfAccount::where('account_code', $agent->financeContact->account_code)->first(); // Bank Account (Card Payment)
        $accountsReceivable = ChartOfAccount::where('account_code', $agent->financeContact->account_code)->first(); // Pay Later

        // Insert voucher details
        VoucherDetail::create([
            'voucher_id' => $voucher->id,
            'account_code' => $walletAccount->account_code,
            'narration' => "Wallet credited for agent",
            'debit_pkr' => 0,
            'credit_pkr' => $pkr_rate,
            'debit_forn' => 0,
            'credit_forn' => $request->amount,
            'exchange_rate' => $exchange_Rate->rate,
            'currency' => $request->currency,
        ]);

        VoucherDetail::create([
            'voucher_id' => $voucher->id,
            'account_code' => $salesAccount->account_code,
            'narration' => "Wallet assignment from company",
            'debit_pkr' => $pkr_rate,
            'credit_pkr' => 0,
            'debit_forn' => $request->amount,
            'credit_forn' => 0,
            'exchange_rate' => $exchange_Rate->rate,
            'currency' => $request->currency,
        ]);

        // Save the updated agent data


        Toast::success('Agent Credit Limit created successfully');
        return redirect()->back()->with('success', 'Agent Credit Limit created successfully');
    }


    public function destroy($id)
    {
        // Find the surcharge by ID
        $agentAddCreditLimit = AgentAddCreditLimit::findOrFail($id);

        // Delete the surcharge
        $agentAddCreditLimit->active = 0;
        $agentAddCreditLimit->save();
        $agent = User::find($agentAddCreditLimit->agent_id);
        if ($agent->credit_limit >= $agentAddCreditLimit->amount) {
            if ($agent->credit_limit_currency == $agentAddCreditLimit->currency) {
                $agent->credit_limit = $agent->credit_limit - $agentAddCreditLimit->amount;
                $agent->save();
            } else {
                if ($agentAddCreditLimit->currency == 'USD') {
                    // Get the conversion rate from USD to the target currency
                    $conversionRate = CurrencyRate::where('base_currency', 'USD')
                        ->where('target_currency', $agent->credit_limit_currency)
                        ->orderby('rate_date')
                        ->first();

                    $previousAmountInUSD = $agentAddCreditLimit->amount * $conversionRate->rate;
                    $credit_limit = $agent->credit_limit - $previousAmountInUSD;
                    $agent->credit_limit = $credit_limit;
                    $agent->save();
                } else {
                // Get the current rate from USD to the agent's credit limit currency
                    $currentRateUSto = CurrencyRate::where('base_currency', 'USD')
                        ->where('target_currency', $agent->credit_limit_currency)
                        ->orderby('rate_date')
                        ->first();

                    // Get the target rate from USD to the requested currency
                    $targetRateUSto = CurrencyRate::where('base_currency', 'USD')
                        ->where('target_currency', $agentAddCreditLimit->currency)
                        ->orderby('rate_date')
                        ->first();

                    if (!$currentRateUSto || !$targetRateUSto) {
                        // Handle the error
                        throw new Exception("Currency rate not found.");
                    }

                    // Convert the existing credit limit to USD and then to the target currency
                    $finalAmountUS  = ($agent->credit_limit / $currentRateUSto->rate) - ($agentAddCreditLimit->amount / $targetRateUSto->rate);

                    if($finalAmountUS>0 ) {

                        $agent->credit_limit = $finalAmountUS*$currentRateUSto->rate;
                        $agent->save();
                    }
                }
            }
        }
        // Return a response or redirect
        Toast::title('Credit Limit deleted successfully')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);

        // Redirect back with a success message
        return redirect()->route('agent.index')->with('status', 'adjustment-deleted');
    }

}
