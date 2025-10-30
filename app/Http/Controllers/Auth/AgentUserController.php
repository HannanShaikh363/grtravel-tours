<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\AgentCodeMail;
use App\Mail\userApprove;
use App\Models\AgentAddCreditLimit;
use App\Models\AgentCompanyFinance;
use App\Models\AgentPricingAdjustment;
use App\Mail\VerificationMail;
use App\Mail\welcomeMail;
use App\Models\CancellationPolicies;
use App\Services\ChartOfAccountService;
use Illuminate\Http\Request;
use ProtoneMedia\Splade\Facades\Toast;
use App\Models\City;
use App\Models\Company;
use App\Models\FinanceContact;
use App\Models\User;
use App\Models\Country;
use App\Models\ChartOfAccount;
use App\Tables\AgentTableConfigurator;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
// use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use ProtoneMedia\Splade\Facades\Splade;
use Illuminate\Support\Str;
use App\Jobs\SendEmailJob;
use Illuminate\Support\Facades\DB;
use App\Mail\NewUser;
use Carbon\Carbon;

class AgentUserController extends Controller
{

    protected $chartOfAccountService;

    public function __construct(ChartOfAccountService $chartOfAccountService)
    {
        $this->chartOfAccountService = $chartOfAccountService;
    }
    /**
     * Display the registration view.
     *
     * @return \Illuminate\View\View
     */
    public function create()
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

        $account_code = ChartOfAccount::where('level', 1)
            ->where('status', 1)
            ->whereIn('parent_id', $account_parentIds)
            ->get(['account_code', 'account_name'])
            ->mapWithKeys(function ($account) {
                return [$account->account_code => $account->account_code . ' || ' . $account->account_name];
            })
            ->toArray();

        $middleware = collect(Route::current()->gatherMiddleware())->toArray();
        $path = in_array('auth', $middleware) ? 'agent.create' : 'agent.create_guest_agent';
        return view($path, [
            'countries' => $countries,
            'middleware' => $middleware,
            'sales_account_code' => $sales_account_code, 
            'account_code'=>$account_code,
            'isCreating' => true,
        ]);
    }

    /**
     * Display the registration view.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $adjustment = AgentPricingAdjustment::with(['agent', 'user'])->get();
        $agentAddCreditLimits = AgentAddCreditLimit::with(['user', 'agent'])->where('active', '=', 1)->get();

        $cancellationPolicies = CancellationPolicies::with(['user'])->where('active', '=', 1)->get();
        return view('agent.index', [
            'agents' => new AgentTableConfigurator(),
            'adjustments' => $adjustment,
            'credit_limits' => $agentAddCreditLimits,
            'cancellationPolicies' => $cancellationPolicies

        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws \Illuminate\Validation\ValidationException
     */

    public function store()
    {
        $request = request();
        
        // Upload logo
        $logoUrl = $this->uploadLogo($request);
        $certificateUrl = $this->uploadCertificate($request);

        // Validate the request data
        try {
            $request->validate($this->agentFormValidateArray('store'), [
                'terms.required' => 'You must accept the terms and conditions.',
                'company.logo.required' => 'You must choose the logo!',
                'company.certificate.required' => 'You must choose the certificate!',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'errors' => $e->errors(), // Return validation errors in JSON
            ], 422); // 422 Unprocessable Entity status code
        }

        // Check if the authenticated user is an admin
        $isCreatedByAdmin = auth()->check() && auth()->user()->hasRole('admin'); // Assuming you're using a roles system

        // Create the User with `created_by_admin` flag
        $user = User::create(array_merge(
            $this->userData($request),
            [
                'created_by_admin' => $isCreatedByAdmin,
                'approved' => $isCreatedByAdmin,
                'agent_code' => User::generateUniqueAgentCode(),
            ] // Set approved flag based on admin creation
        ));


        $company = Company::create($this->companyData($user, $logoUrl, $certificateUrl, $request,));
        if($request->financeContact['assign_account'] == 0){
            // dd($request->financeContact);
            $newAccount = $this->chartOfAccountService->createReceivableAccount($request);
            $saleAccount = $this->chartOfAccountService->getSaleAccount((int)$request->company['city_id']);
            // FinanceContact::where('user_id', $user->id)->update($this->financeData($user, $user->company, $request, $newAccount, $saleAccount));
            $finance = FinanceContact::create($this->financeData($user, $company, $request,$newAccount,$saleAccount));
        }else{

            $finance = FinanceContact::create($this->financeData($user, $company, $request));
        }
        
        $agent_detail = AgentCompanyFinance::create([
            'agent_id' => $user->id,
            'company_id' => $company->id,
            'finance_id' => $finance->id
        ]);
        // If the user is not created by admin, send verification email
        if (!$isCreatedByAdmin) {
            session(['registered_email' => $user->email]);
            $this->sendVerificationEmail($user);
            $currentUrl = request()->url(); // Gets the full URL
            // Extract the path
            $path = parse_url($currentUrl, PHP_URL_PATH);
            // Show the message to verify their email
            if (Str::contains($path, 'auth')) {

                return redirect('/login')->with('status', 'Verification email has been sent to you, please verify before login.');
            }
            
            $adminEmails =  [config('mail.notify_tour'), config('mail.notify_info'), config('mail.notify_account')];
            foreach ($adminEmails as $adminEmail) {
                $mailInstance = new NewUser($user->first_name . ' ' . $user->last_name, $user->email, convertToUserTimeZone($user->created_at, 'F j, Y, g:i a'));
                SendEmailJob::dispatch($adminEmail, $mailInstance);
                
            }
            return response()->json([
                'status' => true,
                'redirect_url' => '/verify-email'
            ]);
        }
        $mailInstance = new AgentCodeMail($user->agent_code, $user->first_name);
        SendEmailJob::dispatch($user->email, $mailInstance);
    

        // If created by admin, check approval status before logging in
        if ($isCreatedByAdmin && !$user->approved) {
            // Show an error message if the user is not approved yet
            return redirect('/login')->withErrors(['email' => 'Your account has not been approved yet.']);
        }

        // Create related company and finance data
        // $company = Company::create($this->companyData($user, $logoUrl, $request, $certificateUrl));
        // FinanceContact::create($this->financeData($user, $company, $request));

        // Fire registered event
        event(new Registered($user));

        // Check the middleware to determine if the user registered themselves
        $middleware = collect(Route::current()->gatherMiddleware())->toArray();

        if (in_array('guest', $middleware)) {
            // If the user registered themselves, auto-login
            Auth::login($user);

            // Check if the user is approved before redirecting them to the dashboard
            if (!$user->approved) {
                // Log out the user if not approved and show an error mesfinanceDatasage
                Auth::logout();
                return redirect('/login')->withErrors(['email' => 'Your account has not been approved yet.']);
            }

            // Redirect to the dashboard if the user is approved
            return redirect('auth.dashboard');
        }

        // Redirect to agent index if created by admin
        return Redirect::route('agent.index')->with('status', 'agent-created');
    }

    public function agent_store()
    {
        $request = request();

        $logoUrl = $this->uploadLogo($request);
        $certificateUrl = $this->uploadCertificate($request);

        try {
            $request->validate($this->agentFormValidateArray('store'), [
                'terms.required' => 'You must accept the terms and conditions.',
                'company.logo.required' => 'You must choose the logo!',
                'company.certificate.required' => 'You must choose the certificate!',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'errors' => $e->errors(), // Return validation errors in JSON
            ], 422); // 422 Unprocessable Entity status code
        }

        $isCreatedByAdmin = auth()->check() && auth()->user()->hasRole('admin'); // Assuming you're using a roles system

        DB::beginTransaction();

        try {
            $user = User::create(array_merge(
                $this->userData($request),
                [
                    'created_by_admin' => $isCreatedByAdmin,
                    'approved' => $isCreatedByAdmin,
                    'agent_code' => User::generateUniqueAgentCode(),
                ]
            ));

            $company = Company::create($this->companyData($user, $logoUrl, $certificateUrl, $request));
            $finance = FinanceContact::create($this->financeData($user, $company, $request));

            $agent_detail = AgentCompanyFinance::create([
                'agent_id' => $user->id,
                'company_id' => $company->id,
                'finance_id' => $finance->id
            ]);

            // If all queries succeed, commit the transaction
            DB::commit();

        } catch (\Exception $e) {
            // If any query fails, rollback the transaction
            DB::rollback();

            return back()->with('error', 'Something went wrong: ' . $e->getMessage());
        }

        if (!$isCreatedByAdmin) {
            session(['registered_email' => $user->email]);
            $this->sendVerificationEmail($user);
            $currentUrl = request()->url(); // Gets the full URL
            // Extract the path
            $path = parse_url($currentUrl, PHP_URL_PATH);
            // Show the message to verify their email
            if (Str::contains($path, 'auth')) {

                return redirect('/login')->with('status', 'Verification email has been sent to you, please verify before login.');
            }
            $adminEmails =  [config('mail.notify_tour'), config('mail.notify_info'), config('mail.notify_account')];
            foreach ($adminEmails as $adminEmail) {
                $mailInstance = new NewUser($user->first_name . ' ' . $user->last_name, $user->email, convertToUserTimeZone($user->created_at, 'F j, Y, g:i a'));
                SendEmailJob::dispatch($adminEmail, $mailInstance);
            }
            return response()->json([
                'status' => true,
                'redirect_url' => '/verify-email'
            ]);
        }
        $mailInstance = new AgentCodeMail($user->agent_code, $user->first_name);
        SendEmailJob::dispatch($user->email, $mailInstance);

        // If created by admin, check approval status before logging in
        if ($isCreatedByAdmin && !$user->approved) {
            // Show an error message if the user is not approved yet
            return redirect('/login')->withErrors(['email' => 'Your account has not been approved yet.']);
        }

        // Fire registered event
        event(new Registered($user));

        // Check the middleware to determine if the user registered themselves
        $middleware = collect(Route::current()->gatherMiddleware())->toArray();

        if (in_array('guest', $middleware)) {
            // If the user registered themselves, auto-login
            Auth::login($user);

            // Check if the user is approved before redirecting them to the dashboard
            if (!$user->approved) {
                // Log out the user if not approved and show an error mesfinanceDatasage
                Auth::logout();
                return redirect('/login')->withErrors(['email' => 'Your account has not been approved yet.']);
            }

            // Redirect to the dashboard if the user is approved
            return redirect('auth.dashboard');
        }

        // Redirect to agent index if created by admin
        return Redirect::route('agent.index')->with('status', 'agent-created');
    }



    public function show(User $user)
    {
        $user->toArray();
        exit;
        //return view('job.job', ['job' => $job]);
    }

    public function edit($id)
    {

        $user = User::where('id', $id)->with(['company', 'financeContact'])->first();

        if (!$user->financeContact) {
            $user->financeContact = new \stdClass(); // Avoid null error
        }

        $user->financeContact->assign_account = 1;
        


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
            $user->financeContact->sales_account_code = array_key_first($sales_account_code);
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
        return view('agent.edit', ['user' => $user, 'countries' => $countries, 'sales_account_code' => $sales_account_code, 'account_code'=>$account_code, 'isCreating' => false,]);
    }

    public function update($agent)
    {
        
        $user = User::where('id', $agent)->with(['company', 'financeContact'])->first();
        $request = request();
        // dd($request->all());
        $request->validate($this->agentFormValidateArray('update'));
        $logoUrl = $this->uploadLogo($request);
        $certificateUrl = $this->uploadCertificate($request);
        $logoUrl = !empty($logoUrl) ? $logoUrl : $user->company->logo;
        $user->update($this->userData($request, $user->id));
        Company::where('user_id', $user->id)->update($this->companyData($user, $logoUrl, $certificateUrl, $request));
        if($request->financeContact['assign_account'] == 0){
            // dd($request->financeContact);
            $newAccount = $this->chartOfAccountService->createReceivableAccount($request);
            $saleAccount = $this->chartOfAccountService->getSaleAccount((int)$request->company['city_id']);
            FinanceContact::where('user_id', $user->id)->update($this->financeData($user, $user->company, $request, $newAccount, $saleAccount));
        }else{

            FinanceContact::where('user_id', $user->id)->update($this->financeData($user, $user->company, $request));
        }
        
        Splade::toast('Agent updated successfully!')->success();

        return Redirect::route('agent.edit', ['agent' => $user->id])->with('status', 'profile-updated');
    }

    public function destroy(User $user)
    {
        $user->toArray();
        exit();
        //return view('job.job', ['job' => $job]);
    }

    /**
     * @param $user
     * @param $company
     * @param mixed $request
     * @return void
     */
    public function financeData($user, $company, mixed $request ,$newAccount = null, $saleAccount = null): array
    {
        return [
            'user_id' => $user->id,
            'company_id' => $company->id,
            'account_name' => $request->financeContact['account_name'] ?? '',
            'account_code' => $newAccount ? $newAccount->account_code : ($request->financeContact['account_code'] ?? ''),
            'sales_account_code' => $saleAccount ? $saleAccount->account_code : ($request->financeContact['sales_account_code'] ?? ''),
            'account_email' => $request->financeContact['account_email'] ?? '',
            'account_contact' => isset($request->financeContact['account_contact']) ? $request->financeContact['account_contact'] : '',
            'phone_code_finance' => $request->financeContact['phone_code_finance'],
            'reservation_name' => $request->financeContact['reservation_name'] ?? '',
            'reservation_email' => $request->financeContact['reservation_email'] ?? '',
            'reservation_contact' =>  isset($request->financeContact['reservation_contact']) ? $request->financeContact['reservation_contact'] : '',
            'management_name' => $request->financeContact['management_name'] ?? '',
            'management_email' => $request->financeContact['management_email'] ?? '',
            'management_contact' =>  isset($request->financeContact['management_contact']) ? $request->financeContact['management_contact'] : '',
        ];
    }

    /**
     * @param $user
     * @param string $logoUrl
     * @param string $certificateUrl
     * @param mixed $request
     * @return void
     */
    public function companyData($user, string $logoUrl, string $certificateUrl, mixed $request): array
    {

        return [
            'user_id' => $user->id,
            'logo' => $logoUrl,
            'certificate' => $certificateUrl,
            'city_id' => $request->company['city_id'],
            'country_id' => $request->company['country_id'],
            'agent_name' => $request->company['agent_name'],
            'agent_number' => isset($request->company['agent_number']) ? $request->company['agent_number'] : '',
            'phone_code_company' => $request->company['phone_code_company'],
            'address' => $request->company['address'],
            'agent_website' => isset($request->company['agent_website']) ? $request->company['agent_website'] : '',
            'zip' => $request->company['zip'],
            'iata_number' => isset($request->company['iata_number']) ? $request->company['iata_number'] : "",
            'iata_status' => isset($request->company['iata_status']) ? $request->company['iata_status'] : null,
            'nature_of_business' => isset($request->company['nature_of_business']) ? $request->company['nature_of_business'] : '',
        ];
    }

    /**
     * @param string $action
     * @return array
     */
    public function agentFormValidateArray(string $action): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'email' => $action == 'store' ?  ['required', 'string', 'email', 'max:255', 'unique:' . User::class] :  ['required', 'string', 'email', 'max:255',],
            'username' => $action == 'store' ? ['required', 'string', 'max:255', 'unique:' . User::class] :  ['required', 'string', 'max:255',],
            'phone' => ['required', 'string', 'max:255'],
            'phone_code' => 'required|string',
            'password' => ['nullable', 'confirmed', Rules\Password::defaults()],
            'terms' => $action == 'store' ? ['required'] : [],
            "last_name" => ['required', 'string', 'max:255'],
            "company.agent_name" => ['required', 'string', 'max:255'],
            "company.agent_number" => ['required', 'string', 'max:255'],
            "company.phone_code_company" => ['required', 'string'],
            "company.address" => ['required', 'string', 'max:255'],
            "company.zip" => ['required', 'string', 'max:255'],
            "company.country_id" => ['required'],
            "company.city_id" => ['required'],
            "company.logo" => ['required'],
            "company.certificate" => ['required'],
            "financeContact.account_name" => ['required', 'string', 'max:255'],
            "financeContact.account_email" => ['required', 'string', 'max:255'],
            "financeContact.account_contact" => ['required', 'string', 'max:255'],
            "financeContact.phone_code_finance" => ['required', 'string'],
        ];
    }

    /**
     * @param mixed $request
     * @return array
     */
    public function userData(mixed $request, int $userId = null): array
    {
        $emailToken = sha1($request->email);
        if ($userId) {
            // When updating, make sure not to conflict with the current user's token
            $existingToken = User::where('email_verification_token', $emailToken)
                ->where('id', '!=', $userId)  // Ignore the current user's token
                ->exists();
            if ($existingToken) {
                // Regenerate the token to avoid conflict
                $emailToken = sha1($request->email . Str::random(10));  // Add randomness to the token
            }
        } else {
            // For new users, check if the email_verification_token already exists in the DB
            $existingToken = User::where('email_verification_token', $emailToken)->exists();
            if ($existingToken) {
                // Regenerate the token if it already exists in the database
                $emailToken = sha1($request->email . Str::random(10));  // Add randomness to ensure uniqueness
            }
        }
       
        $data = [
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'username' => $request->username,
            'phone' => $request->phone,
            'phone_code' => $request->phone_code,
            'email_verification_token' => $emailToken,
            "designation" => $request->designation,
            "mobile" => $request->mobile,
            'type' => 'agent',
            "preferred_currency" => $request->preferred_currency,
            "credit_limit_currency" => $request->preferred_currency
        ];

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        return $data;
    }

    /**
     * @param mixed $request
     * @param string $logoUrl
     * @return string
     */
    public function uploadLogo(mixed $request): string
    {
        // dd($request->hasFile('company.logo'));
        $logoUrl = '';
        if ($request->hasFile('company.logo')) {
            // Retrieve the uploaded file instance
            $file = $request->file('company.logo');
            // Define the directory path where you want to store the file
            $directory = 'uploads/files';
            // Store the file in the directory and get the stored file path
            $path = $file->store($directory, 'public');
            $logoUrl = Storage::url($path);
        }
        return $logoUrl;
    }

    // public function uploadLogo(mixed $request): string
    // {
    //     $logoUrl = '';
    //     if ($request->hasFile('company.logo')) {
    //         $file = $request->file('company.logo');
    //         $directory = 'uploads/files';
    //         $path = $file->store($directory, 's3');
    //         $logoUrl = Storage::disk('s3')->url($path);
    //     }
    //     return $logoUrl;
    // }

    /**
     * @param mixed $request
     * @param string $certificateUrl
     * @return string
     */
    public function uploadCertificate(mixed $request): string
    {
        // dd($request->hasFile('company.logo'));
        $certificateUrl = '';
        if ($request->hasFile('company.certificate')) {
            // Retrieve the uploaded file instance
            $file = $request->file('company.certificate');
            // Define the directory path where you want to store the file
            $directory = 'uploads/files';
            // Store the file in the directory and get the stored file path
            $path = $file->store($directory, 'public');
            $certificateUrl =  Storage::url($path);
        }
        return $certificateUrl;
    }

public function listAgent(Request $request)
{
    $selectedIds = $request->input('values', []); // Always an array
    
    $query = User::query()
        ->where('type', 'agent')
        ->when(!empty($selectedIds), function ($q) use ($selectedIds) {
            $q->orWhereIn('id', $selectedIds);
        });

    $agents = $query->select('id', 'username')->with('company')->get();

    return $agents->map(function ($agent) {
        $company = $agent->company->agent_name ?? '';
        return [
            'id' => $agent->id,
            'username' => "{$agent->username} - {$company}",
        ];
    });
}



    public function approve($id)
    {
        $agent = User::findOrFail($id);
        $hasAccount = $this->chartOfAccountService->hasFinanceAccountCodes($id);

        if(!$hasAccount){
            Toast::title('Assign an account to the user before approval')
            ->info()
            ->rightBottom()
            ->autoDismiss(10);
            return redirect()->back();
        }

        $agentEmail = $agent->email; // Get the agent's email
        $agentName = $agent->first_name; // Get the agent's name

        // Send the booking approval email to the agent
        $mailInstance = new userApprove($agent, $agentName);
        SendEmailJob::dispatch($agentEmail, $mailInstance);

        if ($agent->type == 'agent') {
            $mailInstance = new AgentCodeMail($agent->agent_code, $agentName);
            SendEmailJob::dispatch($agentEmail, $mailInstance);
        }

        $agent->approved = true;
        $agent->save();
        if ($agent->type == 'staff') {

            return redirect()->route('staff.index')->with('status', 'Staff approved successfully.');
        } else {

            return redirect()->route('agent.index')->with('status', 'Agent approved successfully.');
        }
    }

    public function unapprove($id)
    {
        $agent = User::findOrFail($id);
        $agent->approved = false;
        $agent->save();

        return redirect()->route('agent.index')->with('status', 'Agent unapproved successfully!');
    }


    public function searchAgent()
    {
        $request = request();
        $search = $request->get('search');

        // Query users based on the search input
        $users = User::where(function ($query) use ($search) {
            $query->where('first_name', 'like', '%' . $search . '%')
                  ->orWhere('last_name', 'like', '%' . $search . '%')
                  ->orWhere('email', 'like', '%' . $search . '%');
        })
        ->where('type', 'agent')
        ->select('id', 'first_name', 'last_name', 'email')
        ->limit(10)
        ->get();
    

        // Return the response in the format Splade expects
        return response()->json($users->map(function ($user) {
            return [
                'id' => $user->id,
                'label' => $user->first_name . ' ' . $user->last_name . ' (' . $user->email . ')',
            ];
        }));
    }

    public function phoneCode()
    {
        $firstId = '132';
        // Fetch countries with their phone codes


        $phoneCodes = Country::select('name', 'phonecode')->orderByRaw("id = $firstId DESC")->orderBy('id')->get()->map(function ($country) {
            return [
                'label' => "{$country->name} (+{$country->phonecode})",
                'value' => '+' . $country->phonecode,
            ];
        });

        return response()->json($phoneCodes);
    }

    public function sendVerificationEmail($user)
    {
        $verificationUrl = URL::temporarySignedRoute(
            'email.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'token' => $user->email_verification_token]
        );

        $mailInstance = new VerificationMail($verificationUrl, $user->first_name . ' ' . $user->last_name);
        SendEmailJob::dispatch($user->email, $mailInstance);

        return redirect()->route('login_page')->with('status', 'Verification email has been sent.');
    }

    public function resendVerificationEmail(Request $request)
    {
        // Assuming the email is stored in the session or any other source, 
        // or you can retrieve the user directly from the request.

        $email = session('registered_email'); // Or fetch from other context like URL, etc.

        // Validate the email exists in the database
        $user = User::where('email', $email)->first();

        if (!$user) {
            return redirect()->route('agent_login_post')->with('status', 'User with this email does not exist.');
        }

        // Check if the user's email is already verified
        if ($user->hasVerifiedEmail()) {
            return redirect()->route('agent_login_post')->with('status', 'Your email is already verified.');
        }

        // Regenerate the email verification token if needed
        $user->email_verification_token = Str::random(64);
        $user->save();

        // Call the sendVerificationEmail method, passing the user
        $this->sendVerificationEmail($user);

        return redirect()->route('agent_login_post')->with('status', 'Verification email has been resent to your registered email.');
    }

    public function impersonate($id)
    {
        // Check if the current user is an admin
        if (Auth::user()->type !== 'admin' && !auth()->user()->hasRole('admin') ) {
            abort(403, 'Unauthorized action.');
        }

        // Find the agent to impersonate
        $agent = User::findOrFail($id);

        // Impersonate the agent
        Auth::user()->impersonate($agent);

        return redirect()->route('web.dashboard')->with('success', 'You are now impersonating ' . $agent->first_name);
    }

    public function stopImpersonating()
    {

        // Check if the current user is impersonating
        Auth::user()->leaveImpersonation();

        return redirect()->route('auth.dashboard')->with('success', 'You have returned to your account.');
    }

    public function getCountries()
    {
        $country = Country::select('id', 'name')->get();
        return response()->json($country);
    }

    public function getCities($country_id)
    {
        // Fetch cities by country_id
        $cities = City::where('country_id', $country_id)->select('id', 'name')->get();

        // Return as JSON response
        return response()->json($cities);
    }
}
