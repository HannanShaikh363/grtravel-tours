<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\FleetBooking;
use App\Models\TourBooking;
use App\Models\GentingBooking;
use App\Models\User;
use App\Services\BookingService;
use App\Services\TourService;
use App\Services\GentingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use ProtoneMedia\Splade\Facades\Toast;


class FiuuPaymentController extends Controller
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


    public function razerNotify(Request $request, BookingService $bookingService, TourService $tourService, GentingService $gentignService)
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
            Log::info('Booking ID from request:', ['booking_id' => $booking_id]);
            // Payment confirmed, update order status
            
            $booking = Booking::where('id', $booking_id)->first();
            $calculteTaxRate = calculateTaxedPrice((float)$booking->amount);
            $booking->update([
                'payment_type' => 'card',
                'booking_status' => 'vouchered',
                'amount' => $calculteTaxRate['total_amount'],
                'tax_percent' => $calculteTaxRate['tax_percent'],
                'tax_amount' => $calculteTaxRate['tax_amount']
            ]);
            
            $user = User::find($booking->user_id);
            // Create and send PDF
            $hirerEmail = $user->email; // Get hirer email
            $dropOffName = "";
            $pickUpName = "";
            if($booking->booking_type == 'transfer'){

                $fleetBooking = FleetBooking::with('fromLocation.country')->where('id', $booking->booking_type_id)->first();
                $bookingData = $bookingService->prepareBookingData($request, $fleetBooking, $dropOffName, $pickUpName);
                $bookingService->createBookingPDF($bookingData, $hirerEmail, $request, $fleetBooking);

            }else if($booking->booking_type == 'tour' || $booking->booking_type == 'ticket'){

                $tourBooking = TourBooking::with('location.country')->where('id', $booking->booking_type_id)->first();
                $bookingData = $tourService->prepareBookingData($request, $tourBooking, null);
                $tourService->createBookingPDF($bookingData, $hirerEmail, $request, $tourBooking);

            }else if ($booking->booking_type == 'genting_hotel'){

                $gentingBooking = GentingBooking::with('location.country')->where('id', $booking->booking_type_id)->first();
                Log::info('Genting Data', ['id' => $gentingBooking->id]);
                $is_updated = null;
                $bookingData = $gentignService->prepareBookingData($request, $gentingBooking, $is_updated);
                $gentignService->createBookingPDF($bookingData, $hirerEmail, $request, $gentingBooking);
            }

            return response()->json(['message' => 'IPN verified and order status updated'], 200);
        } else {
            Log::info('Invalid IPN signature');
            return response()->json(['message' => 'Invalid IPN signature'], 400);
        }
    }
}
