<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\userApprove;
use App\Mail\VerificationMail;
use App\Mail\welcomeMail;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\URL;
use App\Jobs\SendEmailJob;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function __invoke($id, $token)
    {
        // Retrieve the user by ID
        $user = User::find($id);

        // Check if the user exists and the token matches
        if (!$user || $user->email_verification_token !== $token) {
            abort(403, 'Invalid or expired verification link.');
        }

        // Check if the user has already verified their email
        if ($user->hasVerifiedEmail()) {
            return redirect()->intended('/login')->with('status', 'Your email is already verified.');
        }

        // Mark the email as verified and clear the token
        $user->email_verified_at = now();
        $user->email_verification_token = null; // Clear the token
        $user->save();

        $agentEmail = $user->email; // Get the agent's email
        $agentName = $user->first_name; // Get the agent's name
        $heroImageUrl = asset('img/hero_email.png');
        // Send the booking approval email to the agent
        // Mail::to($agentEmail)->send(new welcomeMail($user, $agentName, $heroImageUrl));
        $mailInstance = new welcomeMail($user, $agentName, $heroImageUrl);
        SendEmailJob::dispatch($agentEmail, $mailInstance);

        return redirect()->intended('/login')->with('status', 'Your email has been verified!');
    }
}
