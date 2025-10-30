<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Mail;
use App\Mail\AgentResetPasswordMail;
use Illuminate\Validation\Rules;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Jobs\SendEmailJob;


class AgentResetPasswordController extends Controller
{
    public function create()
    {
        return view('web.forgot-password'); // Create this view
    }
    public function showLinkRequestForm()
    {
        return view('web.reset-password'); // Create this view
    }

    /**
     * Handle the reset link email sending.
     */
    public function sendResetLinkEmail(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $status = Password::broker('users')->sendResetLink(
            $request->only('email'),
            function ($user, $token) {
                $resetUrl = URL::temporarySignedRoute(
                    'custom.password.reset',
                    now()->addMinutes(60),
                    ['token' => $token, 'email' => $user->email]
                );

                // Send a custom email
                // Mail::to($user->email)->send(new AgentResetPasswordMail($resetUrl));
                $mailInstance = new AgentResetPasswordMail($resetUrl);
                SendEmailJob::dispatch($user->email, $mailInstance);

            }
        );

        return $status == Password::RESET_LINK_SENT
            ? back()->with('status', __($status))
            : back()->withErrors(['email' => __($status)]);
    }

    /**
     * Show the reset password form.
     */
    public function showResetForm(Request $request, $token)
    {
        return view('web.reset-password', ['token' => $token, 'email' => $request->email]); // Create this view
    }

    /**
     * Handle the reset password submission.
     */
    public function reset(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        return $status == Password::PASSWORD_RESET
            ? redirect()->route('password-changed')->with('status', __($status))
            : back()->withInput($request->only('email'))
            ->withErrors(['email' => __($status)]);
    }
}
