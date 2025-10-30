<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Mail\DeviceVerificationMail;
use App\Mail\VerificationMail;
use App\Models\DeviceVerification;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as ipRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Jobs\SendEmailJob;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     *
     * @return \Illuminate\View\View
     */
    public function create()
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(LoginRequest $request)
    {
        // Authenticate the user
        $request->authenticate();

        $user = Auth::user();
        $ipAddress = $request->ip(); // Get the user's IP address
         $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();

        // Check if the user is an agent and if they are approved
        if (($user->type === 'agent' || $user->type === 'staff') && $user->approved === 0) {
            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->back()->withErrors(['email' => 'Your account has not been approved yet.']);
        }
        if(($user->type === 'agent' && Str::contains($request->getRequestUri(), 'auth')) || ($user->type=='staff' &&  Str::contains($request->getRequestUri(), 'auth') && !in_array($user->agent_code, $adminCodes) )){
            Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            throw ValidationException::withMessages([
                    'email' => trans('auth.failed'),
                ]);

        }elseif(!Str::contains($request->getRequestUri(), 'auth') && in_array($user->agent_code, $adminCodes) ){
             Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            throw ValidationException::withMessages([
                    'email' => trans('auth.failed'),
                ]);

        }

        // dd($user);
        
        if($user->type === 'agent' || ($user->type ==='staff' && $user->agent_code != '' && !in_array($user->agent_code, $adminCodes))){
            if(!$user->email_verified_at){
                $verificationUrl = URL::temporarySignedRoute(
                    'email.verify',
                    now()->addMinutes(60),
                    ['id' => $user->id, 'token' => $user->email_verification_token]
                );
                $mailInstance = new VerificationMail($verificationUrl, $user->first_name . ' ' . $user->last_name);
                SendEmailJob::dispatch($user->email, $mailInstance);
                Auth::logout();
                $request->session()->invalidate();
                return redirect()->route('agent_login_page')
            ->withErrors(['email' => 'Verification link has been sent to you email.']);
            }
            // echo "<pre>";print_r(123);die();
            // Check if the IP address is new (not stored for this user yet)
            
            $deviceVerification = DeviceVerification::where('user_id', $user->id)
            ->where('ip_address', $ipAddress)
            ->first();

            // if (!$deviceVerification || $deviceVerification->status !== 'verified') {
            //     // If IP is not verified, send a device verification email
            //     if (!$deviceVerification) {
            //         $verificationToken = Str::random(60);
            //         $verificationUrl = route('verify.device', [
            //             'user_id' => $user->id,
            //             'ip_address' => $ipAddress,
            //             'verification_token' => $verificationToken,
            //         ]);
            //         $logoUrl = asset('img/logo.png'); // Use asset() for logo URL

            //         // Send the email
            //         // Mail::to($user->email)->send(new DeviceVerificationMail($user->first_name, $ipAddress, $verificationUrl, $logoUrl));
            //         $mailInstance = new DeviceVerificationMail($user->first_name, $ipAddress, $verificationUrl, $logoUrl);
            //         SendEmailJob::dispatch($user->email, $mailInstance);
                    
            //         // Store the verification token in the database
            //         DeviceVerification::create([
            //             'user_id' => $user->id,
            //             'ip_address' => $ipAddress,
            //             'verification_token' => $verificationToken,
            //             'status' => 'pending',
            //             'expires_at' => now()->addHours(24), // Optional: Set an expiration time, e.g., 24 hours
            //         ]);
            //     }

            //     // Log the user out and redirect them to the device verification notice page
            //     Auth::logout();
            //     $request->session()->invalidate();
            //     $request->session()->regenerateToken();

            //     return redirect()->route('agent_login_page')
            //     ->withErrors(['email' => 'Please verify your device before logging in.']);
                
            // }
        }
        

        // If the IP is already verified, proceed with the session
        $request->session()->regenerate();
        session(['timezone' => $request->timezone]); 
        $token = $user->createToken('auth_token')->plainTextToken;
        $user->setRememberToken($token);
        $user->save();

        // Redirect based on the request URI
        if ($user->type === 'admin') {
            // Logic 1: If the user type is 'admin', redirect to '/auth/dashboard'
            return redirect()->intended('/auth/dashboard');
        }

        if ($user->type === 'staff') {
            if (in_array($user->agent_code, $adminCodes)) {
                // Logic 2: If the user type is 'staff' and their agent code is in adminCodes, redirect to '/auth/dashboard'
                return redirect()->intended('/auth/dashboard');
            } else {
                // Logic 4: If the user type is 'staff' and their agent code is not in adminCodes, redirect to HOME
                return redirect()->intended(RouteServiceProvider::HOME);
            }
        }

        if ($user->type === 'agent') {
            // Logic 3: If the user type is 'agent', redirect to HOME
            return redirect()->intended(RouteServiceProvider::HOME);
        }

        // Default fallback (optional)
        return redirect()->intended(RouteServiceProvider::HOME);

    }



    /**
     * Destroy an authenticated session.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Request $request)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $currentUrl = request()->url(); // Gets the full URL
        // Extract the path
        $path = parse_url($currentUrl, PHP_URL_PATH);

        // Check if the path contains a specific string
        if (Str::contains($path, 'auth')) {

            return redirect('/auth/login');
        }

        return redirect('/login');
    }
}
