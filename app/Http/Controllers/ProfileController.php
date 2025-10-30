<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Http\Requests\CompanyUpdateRequest;
use App\Http\Requests\UpdateFinanceContactRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\ChartOfAccount;
use App\Models\Country;
use App\Models\Company;
use App\Models\FinanceContact;
use Illuminate\Support\Facades\Redirect;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     *
     * @return \Illuminate\View\View
     */
    public function edit(Request $request)
    {
        $countries = Country::all(['id', 'name'])->pluck('name', 'id');
        $parentIds = ChartOfAccount::where('status', 1)
            ->where('account_name', 'SERVICES SALE')
            ->where('level', 0)
            ->pluck('id');

        $account_parentIds = ChartOfAccount::where('status', 1)
            ->where('account_name', 'ACCOUNTS RECEIVABLE')
            ->where('level', 0)
            ->pluck('id');

        $sales_account_code = ChartOfAccount::where('level', 1)
            ->where('status', 1)
            ->whereIn('parent_id', $parentIds)
            ->get(['account_code', 'account_name'])
            ->mapWithKeys(function ($account) {
                return [$account->account_code => $account->account_code . ' || ' . $account->account_name];
            })
            ->toArray();
        // $account_code = ChartOfAccount::where('level', 1)
        //     ->get(['id', 'account_code'])->pluck('account_code', 'id');
        $account_code = ChartOfAccount::where('level', 1)
            ->where('status', 1)
            ->whereIn('parent_id', $account_parentIds)
            ->get(['account_code', 'account_name'])
            ->mapWithKeys(function ($account) {
                return [$account->account_code => $account->account_code . ' || ' . $account->account_name];
            })
            ->toArray();

            
        return view('profile.edit', [
            'user' => $request->user()->load('financeContact'),
            'sales_account_code' => $sales_account_code, 
            'account_code'=>$account_code,
            'countries' => $countries
        ]);
    }

    /**
     * Update the user's profile information.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(ProfileUpdateRequest $request)
    {
        $user = $request->user();
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }
        
        $request->user()->save();

        // if ($request->has('financeContact')) {
        //     $financeData = $request->input('financeContact');
    
        //     // Update or create financeContact record
        //     $user->financeContact()->updateOrCreate(
        //         ['user_id' => $user->id], // Condition to find existing record
        //         [
        //             'account_code' => $financeData['account_code'],
        //             'sales_account_code' => $financeData['sales_account_code']
        //         ]
        //     );
        // }

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    public function updateCompany(CompanyUpdateRequest $request)
    { 
        $user = $request->user();
    
        // Update User Profile
        $user->fill($request->validated());
    
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }
        
        $user->save();
    
         // Extract 'company' array from the request
        $companyData = $request->input('company');

        // Update or Create Company Information
        $company = Company::updateOrCreate(
            ['user_id' => $user->id], // Condition to check existing record
            [
                'agent_name' => $companyData['agent_name'] ?? null,
                'phone_code_company' => $companyData['phone_code_company'] ?? null,
                'agent_number' => $companyData['agent_number'] ?? null,
                'iata_number' => $companyData['iata_number'] ?? null,
                'iata_status' => $companyData['iata_status'] ?? null,
                'nature_of_business' => $companyData['nature_of_business'] ?? null,
                'agent_website' => $companyData['agent_website'] ?? null,
                'address' => $companyData['address'] ?? null,
                'zip' => $companyData['zip'] ?? null,
                'country_id' => $companyData['country_id'] ?? null,
                'city_id' => $companyData['city_id'] ?? null,
                'logo' => $companyData['logo'] ?? null,
                'certificate' => $companyData['certificate'] ?? null,
            ]
        );
        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    public function updatefinance(UpdateFinanceContactRequest $request){
        $validatedData = $request->validated();
        $user = $request->user();
        $company = Company::where('user_id', $user->id)->first();

        if (!$company) {
            return response()->json(['message' => 'Company not found for this user'], 404);
        }
        $financeContact = FinanceContact::updateOrCreate(
            ['user_id' => $user->id, 'company_id' => $company->id], // Condition to check existing record
            [
                'account_name' => $validatedData['financeContact']['account_name'],
                'account_email' => $validatedData['financeContact']['account_email'],
                'phone_code_finance' => $validatedData['financeContact']['phone_code_finance'],
                'account_contact' => $validatedData['financeContact']['account_contact'],
                'account_code' => $validatedData['financeContact']['account_code'],
                'sales_account_code' => $validatedData['financeContact']['sales_account_code'],
            ]
        );
        return Redirect::route('profile.edit')->with('status', 'profile-updated');
        
    }

    /**
     * Delete the user's account.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Request $request)
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current-password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
