<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\User;
use App\Models\City;
use App\Models\FinanceContact;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ChartOfAccountService
{

    public function createReceivableAccount(Request $request){
    
        $parentAccount = ChartOfAccount::where('account_code', '1010401')->first();

        if (!$parentAccount) {
            return response()->json(['error' => 'Parent Account not found'], 404);
        }

        $lastChild = ChartOfAccount::where('parent_id', $parentAccount->id)
            ->where('account_code', 'like', '1010401%')
            ->orderBy('account_code', 'desc')
            ->first();

        if ($lastChild) {
            $lastCode = (int) Str::after($lastChild->account_code, '1010401'); // Extract last digits
            $newAccountCode = '1010401' . str_pad($lastCode + 1, 5, '0', STR_PAD_LEFT);
        } else {
            $newAccountCode = '101040100001'; // First child under the parent
        }

        $newAccount = ChartOfAccount::create([
            'account_code'   => $newAccountCode,
            'account_name'   => $request->company['agent_name'] ??  $request->financeContact['account_name'],
            'nature'         => 'Accounts Receivable', // Adjust if needed
            'parent_id'      => $parentAccount->id,
            'level'          => $parentAccount->level + 1,
            'type'           => 'Asset', // Adjust if needed
            'currency'       => $request->preferred_currency, // Adjust if needed
            'status'         => 1, // Active status
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return $newAccount;

    }


    public function getSaleAccount($city_id){
        $city = City::find($city_id);

        if (!$city) {
            return response()->json(['message' => 'City not found'], 404);
        }

        $cityName = strtolower($city->name);

        $account = ChartOfAccount::where('parent_id', 19)
                    ->where('status', 1)
                    ->whereRaw("LOWER(account_name) LIKE ?", ["%online%"])
                    ->first();

        // if (!$account) {
        //     $account = ChartOfAccount::whereRaw("LOWER(account_name) LIKE ?", ["%online %"])->first();
        // }
    
        // dd($account);
        return $account;
    }

    public function hasFinanceAccountCodes($user_id)
    {
        $financeContact = FinanceContact::where('user_id', $user_id)->first();
        return ($financeContact && $financeContact->account_code && $financeContact->sales_account_code) ? true : false;
    }

    public function accountFormValidationArray(Request $request): array
    {
        return [
            "currency" => ['required'],
            "check_in" => ['required'],
            "check_out" => ['required'],
            "total_cost" => ['required'],
        ];
    }


}