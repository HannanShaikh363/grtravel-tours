<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\BookingChat;
use App\Models\BookingOffer;
use App\Models\Booking;
use App\Events\MessageSent;
use Illuminate\Http\Request;
use App\Mail\NewMessage;
use App\Jobs\SendEmailJob;
use Illuminate\Support\Facades\Log;


class BookingChatController extends Controller
{
    /**
     * Display the registration view.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        return view('bookingChat.index');
    }

    public function chatBoooking($id){
        $booking_id = $id;
        $booking = Booking::findOrFail($id);
        return view('bookingChat.index', [
            "booking_id" => $booking_id,
            "receiver_id" => $booking->agent_id,
        ]);
    }

    public function sendMessage(Request $request)
    {
        $user = auth()->user();

        $message = BookingChat::create([
            'receiver_id' => $request->receiver_id,
            'booking_id' => $request->booking_id,
            'sender_id' => $request->sender_id,
            'message' => $request->message,
            'offer_id' => $request->offer_id,
            'type' => $request->type,
            'is_read' => $request->is_read
        ]);
        $message->load(['sender', 'receiver']);
        // Broadcast message
        broadcast(new MessageSent($message))->toOthers();

        // $mailInstance = new BookingCancel($fleetBooking, $agentName, $fromLocation, $toLocation, $bookingDate, $location, $booking->booking_type, $amountRefunded);
        // SendEmailJob::dispatch($agentEmail, $mailInstance);
        // log::info("cancellation email send successfully");

        return response()->json(['message' => $message]);
    }

    public function getMessages($booking_id)
    {
        $chat  = BookingChat::where('booking_id', $booking_id)->with(['sender', 'receiver'])->get();
        return $chat;
    }
}
