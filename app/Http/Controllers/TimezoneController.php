<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class TimezoneController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'timezone' => 'required|string',
        ]);

        // Save the timezone to the session
        session(['user_timezone' => $request->input('timezone')]);

        return response()->json(['message' => 'Timezone set successfully.']);
    }
}
