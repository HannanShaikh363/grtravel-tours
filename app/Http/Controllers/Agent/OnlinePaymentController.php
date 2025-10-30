<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\FleetBooking;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use ProtoneMedia\Splade\Facades\Toast;


class OnlinePaymentController extends Controller
{

    public function testPay()
    {
        return view('test.pay');
    }

    //
    public function payWithRazer(Request $request)
    {

        Toast::info('Payment initiated successfully');
        // Redirect to Razer Merchant Services Payment Page
        $order_id = $request->input('orderid');
        $amount = $request->input('amount');
        $status = $request->input('status');
        $merchantID = env('RAZER_MERCHANT_ID');
        $verifykey = env('RAZER_VERIFY_KEY');
        // Verify callback using the verification code
        $vcode = md5($amount . $merchantID . $order_id . $verifykey);
        // Log::info('vcode '.$vcode);
        // Log::info('input vcode '.$request->input('vcode'));
        if ($request->input('vcode') === $vcode && $status == 00) {
            Log::info('Payment successful');
            // Booking::where('id', $order_id)->update(['booking_status' => 'completed']);
            // Payment succeeded, handle post-payment processing (e.g., update order status)
            return redirect()->route('mybookings.index')->with('success', 'Payment initiated successfully');
            // return redirect()->route('thankyou.index')->with('success', 'Payment initiated successfully');
        } else {
            Log::info('Payment failed');
            // Payment failed
            return redirect()->route('mybookings.index')->with('error', 'Payment initiated failed');
            // return redirect()->route('thankyou.index')->with('success', 'Payment initiated successfully');
        }
    }


    public function razerNotify(Request $request, BookingService $bookingService)
    {

        $sec_key = env('RAZER_SECRET_KEY');
        $tranID = $_POST['tranID'] ?? null;
        $booking_id = $_POST['orderid'] ?? null;
        $status = $_POST['status'] ?? null;
        $amount = $_POST['amount'] ?? null;
        $currency = $_POST['currency'] ?? null;
        $appcode = $_POST['appcode'] ?? null;
        $paydate = $_POST['paydate'] ?? null;
        $skey = $_POST['skey'] ?? null;
        $merchant = env('RAZER_MERCHANT_ID');
        /***********************************************************
         * To verify the data integrity sending by PG
         ************************************************************/


        $key0 = md5($tranID . $booking_id . $status . $merchant . $amount . $currency);
        $key1 = md5($paydate . $merchant . $key0 . $appcode . $sec_key);
        if ($skey != $key1) {
            Log::info('Invalid transaction.');
            $status = -1; // Invalid transaction.

        }
        if ($skey === $key1) {
            Log::info('valid transaction.');
            $booking_id = $request->input('orderid') ?? null;
            // Payment confirmed, update order status
            $fleetBooking = FleetBooking::with('fromLocation.country')->where('id', $booking_id)->first();
            $booking = Booking::where('id', $booking_id)->first();
            $booking->update(['booking_status' => 'vouchered']);
            $user = User::find($booking->user_id);
            // Create and send PDF
            $hirerEmail = $user->email; // Get hirer email
            $dropOffName = "";
            $pickUpName = "";
            $bookingData = $bookingService->prepareBookingData($request, $fleetBooking, $dropOffName, $pickUpName);
            $bookingService->createBookingPDF($bookingData, $hirerEmail, $request, $fleetBooking);
            return response()->json(['message' => 'IPN verified and order status updated'], 200);
        } else {
            Log::info('Invalid IPN signature');
            return response()->json(['message' => 'Invalid IPN signature'], 400);
        }
    }
}
