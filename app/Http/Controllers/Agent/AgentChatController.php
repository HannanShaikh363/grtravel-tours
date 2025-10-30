<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Booking;

class AgentChatController extends Controller
{
    public function chatBooking($id){
        $booking_id = $id;
        $booking = Booking::findOrFail($id);
        return view('web.bookingChat.index', [
            "booking_id" => $booking_id,
            "receiver_id" => $booking->agent_id,
        ]);
    }
}
