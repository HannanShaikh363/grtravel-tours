<?php

namespace App\Http\Controllers\Agent;

use App\Helpers\ExportHelper;
use App\Http\Controllers\Controller;
use App\Models\Wishlist;
use App\Models\Rate;
use App\Models\User;
use App\Models\Country;
use App\Models\Company;
use App\Models\AgentCompanyFinance;
use Illuminate\Http\Request;
use App\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Auth;
use App\Models\CancellationPolicies;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules;



class AgentProfileController extends Controller
{
    public function index()
    {
        $roles = Role::all(['id', 'name']);
        $wishlists = Wishlist::with('rate')->where('user_id', Auth::id())->get();
        $profile = User::with(['company', 'financeContact'])->where('id', Auth::id())->first();
        $permissions = Permission::where('name', 'LIKE', '%booking%')->get(['id', 'name']);
        $rolesWithPermissions = [];
        foreach ($roles as $role) {
            $rolesWithPermissions[$role->id] = $role->permissions->pluck('id')->toArray();
        }
        $countries = Country::all(['id', 'name'])->pluck('id', 'name');
        $selectedRole = null;
        return view('web.agent.profile', [
            'wishlists' => $wishlists,
            'profile' => $profile,
            'countries' => $countries,
            'rolesWithPermissions' => $rolesWithPermissions,
            'selectedRole' => $selectedRole,
            'permissions' => $permissions,
            'cancellationPolicy' => CancellationPolicies::where('active', 1)->where('type', 'transfer')->first(),
            'booking_date' => Carbon::now(),
        ]);
    }

    public function uploadLogo(mixed $request): string
    {
        // dd($request->hasFile('company.logo'));
        $logoUrl = '';
        if ($request->hasFile('company_logo')) {
            // Retrieve the uploaded file instance
            $file = $request->file('company_logo');
            // Define the directory path where you want to store the file
            $directory = 'uploads/files';
            // Store the file in the directory and get the stored file path
            $path = $file->store($directory, 'public');

            $logoUrl = Storage::url($path);

            // dd($path, $logoUrl);
        }
        return $logoUrl;
    }

    /**
     * @param mixed $request
     * @param string $certificateUrl
     * @return string
     */
    public function uploadCertificate(mixed $request): string
    {
        // dd($request->hasFile('company.logo'));
        $certificateUrl = '';
        if ($request->hasFile('company_certificatie')) {
            // Retrieve the uploaded file instance
            $file = $request->file('company_certificatie');
            // Define the directory path where you want to store the file
            $directory = 'uploads/files';
            // Store the file in the directory and get the stored file path
            $path = $file->store($directory, 'public');
            $certificateUrl = Storage::url($path);
        }
        return $certificateUrl;
    }

    public function updateProfile()
    {
        // Validation Rules
        $request = request();

        $request->validate($this->agentFormValidateArray('update'));
        $logoUrl = $this->uploadLogo($request);
        $certificateUrl = $this->uploadCertificate($request);
        // dd($logoUrl , $certificateUrl);
        // $validator = Validator::make($request->all(), [
        //     'username' => 'required|string|max:255',
        //     'email' => 'required|email|max:255|unique:users,email,' . $request->user()->id,
        //     'phone' => 'required|string|regex:/^[1-9][0-9]{1,15}$/',
        //     'mobile' => 'required|string|regex:/^[1-9][0-9]{1,15}$/',
        //     'designation' => 'required|string|max:255',
        //     'currency' => 'required|string|in:MYR,PKR,EUR,USD',
        //     'NewPassword' => 'nullable|min:8|confirmed',
        //     'account_name' => 'required|string|max:255',
        //     'agent_number' => 'required|string|max:255',
        //     'iata_number' => 'required|string|max:255',
        //     'company_address' => 'required|string|max:255',
        //     'company_zip' => 'required|string|max:255',
        //     'country' => 'required',
        //     'city' => 'required',
        //     'finance_account_name' => 'required|string|max:255',
        //     'finance_account_email' => 'required|email|max:255',
        //     'finance_account_contact' => 'required|string|max:255',
        // ]);

        // Check Validation Errors
        // if ($validator->fails()) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => $validator->errors(),
        //     ], 422);
        //     // return back()->withErrors($validator)->withInput();
        // }

        try {
            // Fetch the current user
            $user = $request->user();

            // Update User Data
            $user->username = $request->username;
            $user->email = $request->email;
            $user->phone = $request->phone;
            $user->phone_code = $request->phone_code;
            $user->mobile = $request->mobile;
            $user->designation = $request->designation;
            $user->preferred_currency = $request->currency;
            $companyData = [
                'agent_name' => $request->account_name,
                'agent_number' => $request->agent_number,
                'iata_number' => $request->iata_number,
                'address' => $request->company_address,
                'zip' => $request->company_zip,
                'country_id' => $request->country,
                'city_id' => $request->city,
                'agent_website' => $request->agent_website,
                'phone_code_company' => $request->agent_number_code,
                'logo' => $logoUrl ?? null,
                'certificate' => $certificateUrl ?? null,
            ];

            $filteredCompanyData = array_filter($companyData, function ($value) {
                return !is_null($value) && $value !== '';
            });

            $user->company()->update($filteredCompanyData);

            // $user->company()->update([
            //     'agent_name' => $request->account_name,
            //     'agent_number' => $request->agent_number,
            //     'iata_number' => $request->iata_number,
            //     'address' => $request->company_address,
            //     'zip' => $request->company_zip,
            //     'country_id' => $request->country,
            //     'city_id' => $request->city,
            //     'agent_website' => $request->agent_website,
            //     'phone_code_company' => $request->agent_number_code,
            //     'logo' => $logoUrl,
            //     'certificate' => $certificateUrl
            // ]);

            // Update Finance Table Data
            $user->financeContact()->update([
                'account_name' => $request->finance_account_name,
                'account_email' => $request->finance_account_email,
                'account_contact' => $request->finance_account_contact,
                'phone_code_finance' => $request->finance_account_contact_code
            ]);

            // Update Password if Provided
            // if ($request->filled('NewPassword')) {
            //     $user->password = Hash::make($request->input('NewPassword'));
            // }

            // Save Updates
            $user->save();

            // Redirect with Success Message
            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Profile updated successfully.',
            ]);
            // return redirect()->back()->with('success', 'Profile updated successfully.');

        } catch (\Exception $e) {
            // Handle Errors

            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'An error occurred while updating your profile. Please try again. ' . $e,
            ]);
            // return back()->with('error', 'An error occurred while updating your profile. Please try again.');
        }
    }
    public function updateStaff(Request $request)
    {
        // echo "<pre>";print_r($request->all());die();
        // Validation Rules
        // dd($request);
        $validator = Validator::make($request->all(), [
            'username' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . $request->user()->id,
            'phone' => 'required|string|regex:/^[1-9][0-9]{1,15}$/',
            'mobile' => 'required|string|regex:/^[1-9][0-9]{1,15}$/',
            'designation' => 'required|string|max:255',
            'currency' => 'required|string|in:MYR,EUR,USD'
        ]);

        // Check Validation Errors
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors(),
            ], 422);
            // return back()->withErrors($validator)->withInput();
        }

        try {
            // Fetch the current user
            $user = $request->user();

            // Update User Data
            $user->username = $request->input('username');
            $user->email = $request->input('email');
            $user->phone = $request->input('phone');
            $user->mobile = $request->input('mobile');
            $user->designation = $request->input('designation');
            $user->preferred_currency = $request->input('currency');


            // Update Password if Provided
            // if ($request->filled('NewPassword')) {
            //     $user->password = Hash::make($request->input('NewPassword'));
            // }

            // Save Updates
            $user->save();

            // Redirect with Success Message
            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Profile updated successfully.',
            ]);
            // return redirect()->back()->with('success', 'Profile updated successfully.');

        } catch (\Exception $e) {
            // Handle Errors

            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'An error occurred while updating your profile. Please try again. ' . $e,
            ]);
            // return back()->with('error', 'An error occurred while updating your profile. Please try again.');
        }
    }
    public function get_data(Request $request)
    {
        $user = $request->user();
        $staffs = User::where('type', 'staff')
            ->where('agent_code', $user->agent_code)
            ->get()
            ->toArray();
        return response()->json($staffs);

    }
    public function toggleStatus($id)
    {
        $staff = User::find($id);

        if (!$staff) {
            return response()->json(['success' => false, 'message' => 'Staff not found']);
        }

        // Toggle the approved status based on the value sent
        $staff->approved = request('status') == 1; // Set true if 1, false if 0
        $staff->save();

        return response()->json([
            'success' => true,
            'newStatus' => $staff->approved
        ]);
    }
    public function edit($id)
    {
        $staff = User::with('permissions')->find($id);
        $permissions = Permission::where('name', 'LIKE', '%booking%')->get(['id', 'name']);
        if ($staff) {
            return response()->json(['success' => true, 'staff' => $staff, 'modules' => $permissions]);
        }

        return response()->json(['success' => false, 'message' => 'Staff not found']);
    }

    public function update(Request $request, $id)
    {
        // echo "<pre>";print_r($id);
        // echo "<pre>";print_r($request->all());die();
        $staff = User::find($id);
        if ($staff) {
            $staff->first_name = $request->input('first_name');
            $staff->last_name = $request->input('last_name');
            $staff->email = $request->input('email');
            $staff->phone = $request->input('phone');
            $staff->designation = $request->input('designation');
            $staff->preferred_currency = $request->input('preferred_currency');
            $staff->save();
            if ($request->filled('permissions')) {
                $permissions = Permission::whereIn('id', $request->input('permissions'))->pluck('name')->toArray();
                $staff->syncPermissions($permissions);
            } else {
                // If no permissions are provided, remove any existing permissions
                $staff->syncPermissions([]);
            }
            $staff = User::with('permissions')->find($id);
            // echo "<pre>";print_r($staff);die();

            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false, 'message' => 'Staff not found']);
    }

    public function impersonate($id)
    {

        // Find the agent to impersonate
        $agent = User::findOrFail($id);
        // Check if the current user is an admin
        if (Auth::user()->type !== 'agent' && $agent->agent_code == Auth::user()->agent_code) {
            abort(403, 'Unauthorized action.');
        }


        // Impersonate the agent
        Auth::user()->impersonate($agent);
        return redirect()->intended(RouteServiceProvider::HOME)->with('success', 'You are now impersonating ' . $agent->first_name);

        // return redirect()->route('web.dashboard')->with('success', 'You are now impersonating ' . $agent->first_name);
    }



    public function store(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'username' => 'required|string|unique:users,username|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'required|string|max:20',
            'phone_code' => 'required|string|max:10',
            'mobile' => 'required|string|max:20',
            'mobile_code' => 'required|string|max:10',
            'password' => 'required|string|min:8|confirmed',
            'designation' => 'nullable|string|max:255',
            'currency' => 'required|string|in:MYR,EUR,USD',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors(),
            ], 422);
        }
        try {
            // Check if the authenticated user is an admin
            $isCreatedByAdmin = auth()->check() && auth()->user()->hasRole('admin'); // Assuming you're using a roles system

            // Create the User with `created_by_admin` flag
            $loggedInUser = auth()->user(); // Fetch the logged-in user
            $agent = User::with(['company', 'financeContact'])->where('id', $loggedInUser->id)->first();
            $user = User::create(array_merge(
                $this->userData($request),
                [
                    'created_by_admin' => $isCreatedByAdmin,
                    'agent_code' => $loggedInUser->agent_code,
                    "credit_limit_currency" => $loggedInUser->credit_limit_currency,
                    "approved" => 1,

                ] // Set approved flag based on admin creation
            ));

            // Just update the already created user
            // if ($loggedInUser->credit_limit > 0) {
            //     $user->credit_limit = $loggedInUser->credit_limit;
            //     $user->save();
            // }
            $agent_detail = AgentCompanyFinance::create([
                'agent_id' => $user->id,
                'company_id' => $agent->company->id,
                'finance_id' => $agent->financeContact->id
            ]);

            // $user->financeContact()->create([
            //     'company_id'          => $agent->company_id,
            //     'account_name'        => $agent->account_name,
            //     'account_code'        => $agent->account_code,
            //     'sales_account_code'  => $agent->sales_account_code,
            //     'account_email'       => $agent->account_email,
            //     'account_contact'     => $agent->account_contact,
            //     'reservation_name'    => $agent->reservation_name,
            //     'reservation_email'   => $agent->reservation_email,
            //     'reservation_contact' => $agent->reservation_contact,
            //     'management_name'     => $agent->management_name,
            //     'management_email'    => $agent->management_email,
            //     'management_contact'  => $agent->management_contact,
            //     'phone_code_finance'  => $agent->phone_code_finance,
            // ]);

            if ($request->filled('permissions')) {
                $permissions = Permission::whereIn('id', $request->input('permissions'))->pluck('name')->toArray();
                $user->syncPermissions($permissions);
            } else {
                // If no permissions are provided, remove any existing permissions
                $user->syncPermissions([]);
            }
            // echo "<pre>";print_r($user);die();
            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Staff Created successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred: ' . $e->getMessage(),
            ], 500);
        }
        // echo "<pre>";print_r($loggedInUser);die();
        // Assign role to user
        // $roles = $loggedInUser->roles->pluck('name')->toArray(); // Get roles of the logged-in user
        // $user->syncRoles($roles);

        // // Assign the same permissions as the logged-in user
        // $permissions = $loggedInUser->permissions->pluck('name')->toArray(); // Get permissions
        // echo "<pre>";print_r($permissions);die();
        // $user->syncPermissions($permissions);

        // Redirect to agent index if created by admin
        // return Redirect::route('staff.index')->with('status', 'staff-created');
    }
    public function userData(Request $request): array
    {
        $emailToken = sha1($request->email);
        return [
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'username' => $request->username,
            'phone' => $request->phone,
            'phone_code' => $request->phone_code,
            'password' => Hash::make($request->password),
            'email_verification_token' => $emailToken,
            "designation" => $request->designation,
            "mobile" => $request->mobile,
            "preferred_currency" => $request->currency,
            'type' => 'staff',
            'approved' => 1,
        ];
    }

    /**
     * @param string $action
     * @return array
     */
    public function agentFormValidateArray(string $action): array
    {

        return [
            // 'first_name' => ['required', 'string', 'max:255'],
            'email' => $action == 'store' ? ['required', 'string', 'email', 'max:255', 'unique:' . User::class] : ['required', 'string', 'email', 'max:255',],
            'username' => $action == 'store' ? ['required', 'string', 'max:255', 'unique:' . User::class] : ['required', 'string', 'max:255',],
            'phone' => ['required', 'string', 'max:20'],
            'phone_code' => 'required|string',
            'agent_number_code' => 'required|string',
            'finance_account_contact_code' => 'required|string',
            // 'NewPassword' => ['required', 'confirmed', Rules\Password::defaults()],
            'terms' => $action == 'store' ? ['required'] : [],
            // "last_name" => ['required', 'string', 'max:255'],
            "account_name" => ['required', 'string', 'max:255'],
            "agent_number" => ['required', 'string', 'max:20'],
            // "company.phone_code_company" => ['required', 'string'],
            "company_address" => ['required', 'string', 'max:255'],
            "company_zip" => ['required', 'string', 'max:255'],
            // "country" => ['required'],
            // "city" => ['required'],
            // "company.logo" => ['required'],
            // "company.certificate" => ['required'],
            "finance_account_name" => ['required', 'string', 'max:255'],
            "finance_account_email" => ['required', 'string', 'max:255'],
            "finance_account_contact" => ['required', 'string', 'max:20'],
            // "financeContact.phone_code_finance" => ['required', 'string'],
        ];
    }

    public function getuserData(mixed $request): array
    {
        $emailToken = sha1($request->email);
        return [
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'username' => $request->username,
            'phone' => $request->phone,
            'phone_code' => $request->phone_code,
            'password' => Hash::make($request->password),
            'email_verification_token' => $emailToken,
            "designation" => $request->designation,
            "mobile" => $request->mobile,
            'type' => 'agent',
            "preferred_currency" => $request->preferred_currency,
            "credit_limit_currency" => $request->preferred_currency
        ];
    }

    public function exportBookings(Request $request)
    {
        // Get your data
        $data = User::where('agent_code', $request->user()->agent_code)
            ->where('type', 'staff')
            ->select('id', 'first_name', 'last_name', 'email', 'username', 'phone', 'mobile', 'designation', 'preferred_currency')
            ->get();
        $headings = ['ID', 'FirstName', 'LastName', 'Email', 'Username', 'Phone', 'Mobile', 'Designation', 'Preferred Currency']; // Replace with your actual headings

        // Create an instance of ExportHelper and pass the data and headings
        $export = new ExportHelper($data, $headings);

        // Return the export as a downloadable Excel file
        return Excel::download($export, 'all_staff.xlsx');
    }
}
