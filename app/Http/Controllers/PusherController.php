<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Pusher\Pusher;

class PusherController extends Controller
{
    public function auth(Request $request)
    {
        if (!Auth::check()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $pusher = new Pusher(
            env('PUSHER_APP_KEY'),
            env('PUSHER_APP_SECRET'),
            env('PUSHER_APP_ID'),
            [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'useTLS' => true,
            ]
        );

        $socket_id = $request->socket_id;
        $channel_name = $request->channel_name;
        $auth = $pusher->authorizeChannel($channel_name, $socket_id);

        return response()->json($auth);
    }
}
