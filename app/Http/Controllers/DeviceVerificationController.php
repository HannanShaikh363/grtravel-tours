<?php

namespace App\Http\Controllers;

use App\Models\DeviceVerification;
use App\Models\User;
use Illuminate\Http\Request;

class DeviceVerificationController extends Controller
{
    public function verifyDevice(Request $request)
    {

        // Retrieve the parameters
        $userId = $request->route('user_id');
        $ipAddress = $request->route('ip_address');
        $token = $request->route('verification_token');

        // Find the user
        $user = User::findOrFail($userId);

        // Continue your verification logic
        $verification = DeviceVerification::where('user_id', $user->id)
            ->where('ip_address', $ipAddress)
            ->where('verification_token', $token)
            ->first();

        if (!$verification) {
            return redirect()->route('agent_login_page')->withErrors('Invalid or expired verification link.');
        }

        // Check if the token has expired
        if ($verification->expires_at && $verification->expires_at < now()) {
            return redirect()->route('agent_login_page')->withErrors('Verification token has expired.');
        }

        // Mark the device as verified
        $verification->status = 'verified';
        $verification->save();

        // Redirect the user to their dashboard
        return redirect()->route('agent.dashboard')->with('status', 'Device verified successfully!');
    }
}
