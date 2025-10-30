<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;

use App\Mail\BookingCancel;
use App\Models\Booking;
use App\Models\GentingBooking;
use App\Models\CancellationDeductions;
use App\Models\HotelBooking;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherDetail;
use App\Models\VoucherType;
use App\Models\ChartOfAccount;
use App\Services\CurrencyService;
use App\Services\RezliveHotelService;
use Illuminate\Support\Carbon;
use App\Models\CancellationPolicies;
use App\Models\FleetBooking;
use App\Models\Location;
use App\Models\TransferHotel;
use Exception;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\Request;
use Laravel\Prompts\Concerns\FakesInputOutput;
use ProtoneMedia\Splade\Facades\Toast;
use App\Jobs\SendEmailJob;
use App\Models\TourBooking;
use App\Models\TourRate;
use App\Models\AgentPricingAdjustment;
use Illuminate\Support\Facades\Log;


class CancellationDeductionsController extends Controller
{
    //


    public function deductionViaService($service_id, $service_type)
    {
        $user = auth()->user();
        $booking = Booking::where('booking_type_id', $service_id)->where('booking_type', 'transfer')->first();
        $fleetBooking = FleetBooking::where('id', $booking->booking_type_id)->first();
        $getUser = User::where('id', $booking->user_id)->first();
        $fleetBooking->approved = 0;
        // $fleetBooking->save();
        $transferHotels = TransferHotel::where('booking_id', $fleetBooking->id)->get();

        // Extract names from the records
        $pickupHotelName = $transferHotels->first()->pickup_hotel_name ?? 'N/A';
        $returnDropoffHotelName = $transferHotels->first()->return_dropoff_hotel_name ?? 'N/A';

        $dropoffHotelName = $transferHotels->skip(1)->first()->dropoff_hotel_name ?? 'N/A';
        $returnPickupHotelName = $transferHotels->skip(1)->first()->return_pickup_hotel_name ?? 'N/A';

        // Handle cases with only one record
        if ($transferHotels->count() === 1) {
            $dropoffHotelName = $transferHotels->first()->dropoff_hotel_name ?? 'N/A';
            $returnPickupHotelName = $transferHotels->first()->return_pickup_hotel_name ?? 'N/A';
        }

        // Assign to/from locations based on these values
        $toLocation = $dropoffHotelName !== 'N/A'
            ? $dropoffHotelName
            : Location::where('id', $fleetBooking->to_location_id)->value('name');
        $fromLocation = $pickupHotelName !== 'N/A'
            ? $pickupHotelName
            : Location::where('id', $fleetBooking->from_location_id)->value('name');

        $location = null;

        // Find active cancellation policy for the booking type
        $cancellationPolicy = CancellationPolicies::where('active', 1)
            ->where('type', $booking->booking_type)
            ->first();
        // Calculate the deduction percentage based on the policy
        $percentage = $this->deductionCharges($booking->booking_type, $booking->service_date, $cancellationPolicy);
        // Deduction and refund logic
        if ($percentage !== false) {
            $deduction = ($percentage / 100) * $booking->amount;
            $refundAmount = $booking->amount - $deduction;
        } else {
            // Full refund if no valid policy is found
            $deduction = 0;
            $refundAmount = $booking->amount;
        }

        $bookingCurrency = $booking->currency;
        $userCurrency = $getUser->credit_limit_currency;
        // Handle currency conversion
        if ($bookingCurrency != $userCurrency) {
            if ($userCurrency == 'USD' && $bookingCurrency != 'USD') {
                $deduction = CurrencyService::convertCurrencyToUsd($bookingCurrency, $deduction);
                $refundAmount = CurrencyService::convertCurrencyToUsd($bookingCurrency, $refundAmount);
            } elseif ($userCurrency != 'USD' && $bookingCurrency != 'USD') {
                $deduction = CurrencyService::convertCurrencyToUsd($bookingCurrency, $deduction);
                $refundAmount = CurrencyService::convertCurrencyToUsd($bookingCurrency, $refundAmount);
                $refundAmount = CurrencyService::convertCurrencyFromUsd($userCurrency, $refundAmount);
            }
        }

        // Update user's credit limit
        $user_credit_limit = $getUser->credit_limit + $refundAmount;
        // Log cancellation details
        $cancellationDeduction = new CancellationDeductions();
        $cancellationDeduction->cancellation_policy_id = $cancellationPolicy->id ?? null; // Null if no policy exists
        $cancellationDeduction->service_id = $booking->booking_type_id;
        $cancellationDeduction->service_type = $booking->booking_type;
        $cancellationDeduction->deduction = $deduction;
        $cancellationDeduction->user_id = $user->id;
        $cancellationDeduction->save();

        // Update booking and user
        $booking->booking_status = 'cancelled';
        $booking->save();
        $getUser->credit_limit = $user_credit_limit;
        $getUser->save();

        $this->createVoucherForCancelBooking($booking, $refundAmount, $userCurrency);

        // Determine if the booking was created by admin
        $isCancelByAdmin = $fleetBooking->created_by_admin;

        // Notify the agent if the booking was not canceled by admin and if email hasn't been sent
        if (!$isCancelByAdmin) {
            $agentInfo = User::find($fleetBooking->user_id, ['email', 'first_name']);

            if ($agentInfo) {
                $amountRefunded = $fleetBooking->currency . ' ' . $refundAmount;
                $agentEmail = $agentInfo->email;
                $agentName = $agentInfo->first_name;
                $bookingDate = convertToUserTimeZone($booking->booking_date);
                $mailInstance = new BookingCancel($fleetBooking, $agentName, $fromLocation, $toLocation, $bookingDate, $location, $booking->booking_type, $amountRefunded);
                SendEmailJob::dispatch($agentEmail, $mailInstance);
                $admin = new BookingCancel(
                    $fleetBooking,
                    'Admin',
                    $fromLocation, $toLocation, $bookingDate, $location,
                    $booking->booking_type,
                    $amountRefunded
                );
                $adminEmails =  [config('mail.notify_tour'), config('mail.notify_info'), config('mail.notify_account')];
                foreach ($adminEmails as $adminEmail) {
                    SendEmailJob::dispatch($adminEmail, $admin);
                }
                log::info("cancellation email send successfully");

                // Mark the email as sent to avoid duplicate notifications
                $fleetBooking->email_sent = true;
                $fleetBooking->save();
            }
        }

        // Return success message
        return redirect()->back()->with(
            'success',
            $percentage !== false
            ? 'Booking cancelled successfully. Refund Amount: ' . number_format($refundAmount, 2)
            : 'Booking cancelled successfully with a full refund of ' . number_format($refundAmount, 2)
        );
    }

    public function tourDeduction($service_id, $service_type)
    {

        $user = auth()->user();
        $booking = Booking::where('booking_type_id', $service_id)
            ->whereIn('booking_type', ['tour', 'ticket'])
            ->first();

        $tourBooking = TourBooking::where('id', $booking->booking_type_id)->first();
        $tourRate = TourRate::where('id', $tourBooking->rate_id)->first();
        $getUser = User::where('id', $booking->user_id)->first();
        $tourBooking->approved = 0;

        // Assign to/from locations based on these values
        $location = Location::where('id', $tourBooking->location_id)->value('name');
        // Find active cancellation policy for the booking type
        $cancellationPolicy = CancellationPolicies::where('active', 1)
            ->whereIn('type', [$booking->booking_type, 'tour'])
            ->first();
        // Calculate the deduction percentage based on the policy
        $percentage = $this->deductionCharges($booking->booking_type, $booking->service_date, $cancellationPolicy);
        // Deduction and refund logic
        if ($percentage !== false) {
            $deduction = ($percentage / 100) * $booking->amount;
            $refundAmount = $booking->amount - $deduction;
        } else {
            // Full refund if no valid policy is found
            $deduction = 0;
            $refundAmount = $booking->amount;
        }

        $bookingCurrency = $booking->currency;
        $userCurrency = $getUser->credit_limit_currency;
        // Handle currency conversion
        if ($bookingCurrency != $userCurrency) {
            if ($userCurrency == 'USD' && $bookingCurrency != 'USD') {
                $deduction = CurrencyService::convertCurrencyToUsd($bookingCurrency, $deduction);
                $refundAmount = CurrencyService::convertCurrencyToUsd($bookingCurrency, $refundAmount);
            } elseif ($userCurrency != 'USD' && $bookingCurrency != 'USD') {
                $deduction = CurrencyService::convertCurrencyToUsd($bookingCurrency, $deduction);
                $refundAmount = CurrencyService::convertCurrencyToUsd($bookingCurrency, $refundAmount);
                $refundAmount = CurrencyService::convertCurrencyFromUsd($userCurrency, $refundAmount);
            } elseif ($bookingCurrency == 'USD' && $userCurrency != 'USD') {
                // Convert from USD to user's currency
                $refundAmount = CurrencyService::convertCurrencyFromUsd($userCurrency, $refundAmount);
            }
        }

        // Update user's credit limit
        $user_credit_limit = $getUser->credit_limit + $refundAmount;
        // Log cancellation details
        $cancellationDeduction = new CancellationDeductions();
        $cancellationDeduction->cancellation_policy_id = $cancellationPolicy->id ?? null; // Null if no policy exists
        $cancellationDeduction->service_id = $booking->id;
        $cancellationDeduction->service_type = $booking->booking_type;
        $cancellationDeduction->deduction = $deduction;
        $cancellationDeduction->user_id = $user->id;
        $cancellationDeduction->save();

        // Update booking and user
        $booking->booking_status = 'cancelled';
        $booking->save();
        $getUser->credit_limit = $user_credit_limit;
        $getUser->save();

        $this->createVoucherForCancelBooking($booking, $refundAmount, $userCurrency);

        // Determine if the booking was created by admin
        $isCancelByAdmin = $tourBooking->created_by_admin;

        // Notify the agent if the booking was not canceled by admin and if email hasn't been sent
        if (!$isCancelByAdmin) {
            $agentInfo = User::find($tourBooking->user_id, ['email', 'first_name']);

            if ($agentInfo) {
                $amountRefunded = $userCurrency . ' ' . $refundAmount;
                $agentEmail = $agentInfo->email;
                $agentName = $agentInfo->first_name;
                $fromLocation = null;
                $toLocation = null;
                // Send booking cancellation email to the agent
                // Mail::to($agentEmail)->send(new BookingCancel($tourBooking, $agentName, $fromLocation, $toLocation));
                $bookingDate = convertToUserTimeZone($booking->booking_date);
                $mailInstance = new BookingCancel($tourBooking, $agentName, $fromLocation, $toLocation, $bookingDate, $location, $booking->booking_type, $amountRefunded);
                SendEmailJob::dispatch($agentEmail, $mailInstance);
                $admin = new BookingCancel(
                    $tourBooking,
                    'Admin',
                    $fromLocation, $toLocation, $bookingDate, $location,
                    $booking->booking_type,
                    $amountRefunded
                );
                $adminEmails =  [config('mail.notify_tour'), config('mail.notify_info'), config('mail.notify_account')];
                foreach ($adminEmails as $adminEmail) {
                    SendEmailJob::dispatch($adminEmail, $admin);
                }
                log::info("cancellation email send successfully");

                // Mark the email as sent to avoid duplicate notifications
                $tourBooking->email_sent = true;
                $tourBooking->save();
            }
        }

        // Return success message
        return redirect()->back()->with(
            'success',
            $percentage !== false
            ? 'Booking cancelled successfully. Refund Amount: ' . number_format($refundAmount, 2)
            : 'Booking cancelled successfully with a full refund of ' . number_format($refundAmount, 2)
        );
    }

    public function cancelledGentingBooking($service_id, $service_type)
    {
        $user = auth()->user();
        $booking = Booking::where('booking_type_id', $service_id)
            ->whereIn('booking_type', [$service_type])
            ->first();

        $gentingBooking = GentingBooking::where('id', $booking->booking_type_id)->first();
        $getUser = User::where('id', $booking->user_id)->first();
        $gentingBooking->approved = 0;

        // Update booking and user
        $booking->booking_status = 'cancelled';
        $booking->save();

        // Determine if the booking was created by admin
        $isCancelByAdmin = $gentingBooking->created_by_admin;

        // Notify the agent if the booking was not canceled by admin and if email hasn't been sent
        if (!$isCancelByAdmin) {
            $agentInfo = User::find($gentingBooking->user_id, ['email', 'first_name']);

            if ($agentInfo) {

                $agentEmail = $agentInfo->email;
                $agentName = $agentInfo->first_name;
                $fromLocation = null;
                $toLocation = null;
                $location = null;
                $amountRefunded = null;
                // Send booking cancellation email to the agent
                $bookingDate = convertToUserTimeZone($booking->booking_date);
                $mailInstance = new BookingCancel($gentingBooking, $agentName, $fromLocation, $toLocation, $bookingDate, $location, $booking->booking_type, $amountRefunded);
                SendEmailJob::dispatch($agentEmail, $mailInstance);
                $admin = new BookingCancel(
                    $gentingBooking,
                    'Admin',
                    $fromLocation, $toLocation, $bookingDate, $location,
                    $booking->booking_type,
                    $amountRefunded
                );
                $adminEmails =  [config('mail.notify_tour'), config('mail.notify_info'), config('mail.notify_account')];
                foreach ($adminEmails as $adminEmail) {
                    SendEmailJob::dispatch($adminEmail, $admin);
                }
                log::info("cancellation email send successfully");

                $gentingBooking->email_sent = true;
                $gentingBooking->save();
            }
        }

        // Return success message
        return redirect()->back()->with(
            'success',
            'Booking cancelled successfully.'
        );
    }

    public function cancelledHotelBooking($service_id, $service_type, $rezlive_bookingid, $rezlive_bookingcode)
    {

        $response = app(RezliveHotelService::class)->cancelHotelBooking([
            'booking_id' => $rezlive_bookingid,
            'booking_code' => $rezlive_bookingcode,
        ]);

        if (!isset($response->Status) || strtolower((string)$response->Status) !== 'true') {
            return redirect()->back()->with('error', 'Cancellation failed from Rezlive. Please try again.');
        }

        $booking = Booking::where('booking_type_id', $service_id)
            ->where('booking_type', $service_type)
            ->first();

        
        if (!$booking) {
            return redirect()->back()->with('error', 'Booking not found.');
        }

        $hotelBooking = HotelBooking::where('id',$booking->booking_type_id)->first();
        $getUser = User::find($booking->user_id);
        $userCurrency = $getUser->credit_limit_currency;
        $bookingCurrency = $booking->currency;
        if (!$hotelBooking || !$getUser) {
            return redirect()->back()->with('error', 'Related booking or user not found.');
        }

        /******************/ 

        $adjustmentRates = AgentPricingAdjustment::getCurrentAdjustmentRate($booking->user_id, 'hotel');
        $chargeAmount = $response->CancellationCharges;
        $currency = $response->Currency;
        

        if ((float) $chargeAmount != 0) {

            $deduction = app(RezliveHotelService::class)->applyCurrencyConversion((float) $chargeAmount, (string) $currency, $bookingCurrency);
            foreach ($adjustmentRates as $adjustmentRate) {
                $deduction = round(app(RezliveHotelService::class)->applyAdjustment($deduction, $adjustmentRate), 2);
            
            }

            $refundAmount = $booking->amount - $deduction;
        } else {
            
            $deduction = 0;
            $refundAmount = $booking->amount;
        }

        // Handle currency conversion
        if ($bookingCurrency != $userCurrency) {
            if ($userCurrency == 'USD' && $bookingCurrency != 'USD') {
                $deduction = CurrencyService::convertCurrencyToUsd($bookingCurrency, $deduction);
                $refundAmount = CurrencyService::convertCurrencyToUsd($bookingCurrency, $refundAmount);
            } elseif ($userCurrency != 'USD' && $bookingCurrency != 'USD') {
                $deduction = CurrencyService::convertCurrencyToUsd($bookingCurrency, $deduction);
                $refundAmount = CurrencyService::convertCurrencyToUsd($bookingCurrency, $refundAmount);
                $refundAmount = CurrencyService::convertCurrencyFromUsd($userCurrency, $refundAmount);
            } elseif ($bookingCurrency == 'USD' && $userCurrency != 'USD') {
                // Convert from USD to user's currency
                $refundAmount = CurrencyService::convertCurrencyFromUsd($userCurrency, $refundAmount);
            }
        }

        // Update user's credit limit
        $user_credit_limit = $getUser->credit_limit + $refundAmount;
        // Log cancellation details
        $cancellationDeduction = new CancellationDeductions();
        $cancellationDeduction->cancellation_policy_id =  0; // Null if no policy exists
        $cancellationDeduction->service_id = $booking->id;
        $cancellationDeduction->service_type = $booking->booking_type;
        $cancellationDeduction->deduction = $deduction;
        $cancellationDeduction->user_id = $getUser->id;
        $cancellationDeduction->save();

        // Update booking and user
        $booking->booking_status = 'cancelled';
        $booking->save();
        $getUser->credit_limit = $user_credit_limit;
        $getUser->save();

        $this->createVoucherForCancelBooking($booking, $refundAmount, $userCurrency);

        /**********************/ 

        $hotelBooking->approved = 0;
        $hotelBooking->save();

        // $booking->booking_status = 'cancelled';
        // $booking->save();

        if (!$hotelBooking->created_by_admin) {
            $agentInfo = User::find($hotelBooking->user_id, ['email', 'first_name']);

            if ($agentInfo) {
                $bookingDate = convertToUserTimeZone($booking->booking_date);

                $mailInstance = new BookingCancel(
                    $hotelBooking,
                    $agentInfo->first_name,
                    null, null, $bookingDate, null,
                    $booking->booking_type,
                    null
                );
                SendEmailJob::dispatch($agentInfo->email, $mailInstance);
                $admin = new BookingCancel(
                    $hotelBooking,
                    'Admin',
                    null, null, $bookingDate, null,
                    $booking->booking_type,
                    null
                );
                $adminEmails =  [config('mail.notify_tour'), config('mail.notify_info'), config('mail.notify_account')];
                foreach ($adminEmails as $adminEmail) {
                    SendEmailJob::dispatch($adminEmail, $admin);
                }

                $hotelBooking->email_sent = true;
                $hotelBooking->save();
            }
        }

        // Step 7: Redirect with success
        return redirect()->back()->with('success', 'Booking cancelled successfully.');
    }


    public function deductionCharges($type, $service_date, $cancellationPolicy)
    {
        $targetDate = Carbon::parse($service_date)->startOfDay();
        // Get the current date and time
        $currentDate = Carbon::parse(convertToUserTimeZone(Carbon::now(), 'Y-m-d H:i:s'))->startOfDay();
        // Calculate the difference in days
        $remainingDays = $currentDate->diffInDays($targetDate, false); // 'false' to allow negative values if targetDate is in the past
        if (!$cancellationPolicy) {
            return false;
        }
        $cancellationPolicyData = json_decode($cancellationPolicy->cancellation_policies_meta, true);
        $cancellationPolicyCollection = collect($cancellationPolicyData);
        $sortedCancellationPolicy = $cancellationPolicyCollection->sortBy('days_before')->values()->toArray();


        foreach ($sortedCancellationPolicy as $key => $value) {

            if ($sortedCancellationPolicy[$key]['days_before'] >= $remainingDays) {
                $deduction = $sortedCancellationPolicy[$key]['percentage'];
                return $deduction;
            }
        }
        return false;
    }

    public function fullRefund(Request $request, $service_id, $service_type)
    {
        try {
            // Retrieve booking and validate existence
            $booking = Booking::where('id', $service_id)->where('booking_type', $service_type)->first();
            if (!$booking) {
                throw new Exception("Booking not found.");
            }

            // Get the user associated with the booking
            $user = User::find($booking->user_id);
            if (!$user || $user->type === 'admin') {
                throw new Exception("Refund is only applicable for bookings made by an agent.");
            }

            if ($service_type == 'tour' || $service_type == 'ticket') {
                $fleetBooking = TourBooking::where('booking_id', $booking->id)->first();
            } else if ($service_type == 'genting_hotel') {
                $fleetBooking = GentingBooking::where('booking_id', $booking->id)->first();
            } else {
                $fleetBooking = FleetBooking::where('booking_id', $booking->id)->first();
            }
            if (!$fleetBooking) {
                throw new Exception("Fleet booking not found.");
            }
            $amount = $booking->amount;
            $refundPerc = $request->refund_perc;

            // Calculate the new amount after deduction
            $refundAmount = ($amount * $refundPerc) / 100;
            $bookingCurrency = $booking->currency;
            $userCurrency = $user->credit_limit_currency;

            // Handle currency conversion
            if ($bookingCurrency != $userCurrency) {
                if ($userCurrency == 'USD' && $bookingCurrency != 'USD') {
                    $refundAmount = CurrencyService::convertCurrencyToUsd($bookingCurrency, $refundAmount);
                } elseif ($userCurrency != 'USD' && $bookingCurrency != 'USD') {
                    $refundAmount = CurrencyService::convertCurrencyToUsd($bookingCurrency, $refundAmount);
                    $refundAmount = CurrencyService::convertCurrencyFromUsd($userCurrency, $refundAmount);
                }
            }

            // Update booking status to 'cancelled'
            $booking->booking_status = 'cancelled';
            $booking->save();
            $fleetBooking->approved = false;
            $fleetBooking->save();

            // Update agent's credit limit
            $user->credit_limit += $refundAmount;
            $user->save();
            $this->createVoucherForCancelBooking($booking, $refundAmount, $userCurrency);

            $agentInfo = User::find($booking->user_id, ['email', 'first_name']);

            if ($agentInfo) {
                $agentEmail = $agentInfo->email;
                $agentName = $agentInfo->first_name;
                $bookingDate = convertToUserTimeZone($booking->booking_date);
                if ($service_type == 'tour' || $service_type == 'ticket') {
                    $amountRefunded = $fleetBooking->currency . ' ' . $refundAmount;
                    $location = Location::where('id', $fleetBooking->location_id)->value('name');
                    $fromLocation = null;
                    $toLocation = null;
                    $mailInstance = new BookingCancel($fleetBooking, $agentName, $fromLocation, $toLocation, $bookingDate, $location, $booking->booking_type, $amountRefunded);
                    SendEmailJob::dispatch($agentEmail, $mailInstance);
                } else if ($service_type == 'genting_hotel') {
                    $amountRefunded = $fleetBooking->currency . ' ' . $refundAmount;
                    $location = Location::where('id', $fleetBooking->location_id)->value('name');
                    $fromLocation = null;
                    $toLocation = null;
                    $mailInstance = new BookingCancel($fleetBooking, $agentName, $fromLocation, $toLocation, $bookingDate, $location, $booking->booking_type, $amountRefunded);
                    SendEmailJob::dispatch($agentEmail, $mailInstance);
                } else {
                    $amountRefunded = $fleetBooking->currency . ' ' . $refundAmount;
                    $transferHotels = TransferHotel::where('booking_id', $fleetBooking->id)->get();
                    // Extract names from the records
                    $pickupHotelName = $transferHotels->first()->pickup_hotel_name ?? 'N/A';
                    $returnDropoffHotelName = $transferHotels->first()->return_dropoff_hotel_name ?? 'N/A';

                    $dropoffHotelName = $transferHotels->skip(1)->first()->dropoff_hotel_name ?? 'N/A';
                    $returnPickupHotelName = $transferHotels->skip(1)->first()->return_pickup_hotel_name ?? 'N/A';

                    // Handle cases with only one record
                    if ($transferHotels->count() === 1) {
                        $dropoffHotelName = $transferHotels->first()->dropoff_hotel_name ?? 'N/A';
                        $returnPickupHotelName = $transferHotels->first()->return_pickup_hotel_name ?? 'N/A';
                    }
                    // Assign to/from locations based on these values
                    $toLocation = $dropoffHotelName !== 'N/A'
                        ? $dropoffHotelName
                        : Location::where('id', $fleetBooking->to_location_id)->value('name');
                    $fromLocation = $pickupHotelName !== 'N/A'
                        ? $pickupHotelName
                        : Location::where('id', $fleetBooking->from_location_id)->value('name');

                    $location = null;
                    $mailInstance = new BookingCancel($fleetBooking, $agentName, $fromLocation, $toLocation, $bookingDate, $location, $booking->booking_type, $amountRefunded);
                    SendEmailJob::dispatch($agentEmail, $mailInstance);
                }
            }
            // Success notification
            Toast::title('Booking cancelled successfully. Refund Amount: ' . number_format($refundAmount, 2))
                ->success()
                ->rightBottom()
                ->autoDismiss(5);

            return redirect()->back();
        } catch (Exception $e) {
            // Error notification
            Toast::title('An error occurred: ' . $e->getMessage())
                ->danger()
                ->rightBottom()
                ->autoDismiss(5);

            return redirect()->back();
        }
    }

    private static function createVoucherForCancelBooking($booking, $refundAmount, $userCurrency)
    {
        // Step 1: Get Voucher Type ID
        $voucherType = VoucherType::where('code', 'SV-J')->first();
        $voucherTypeId = $voucherType ? $voucherType->id : 1;

        $agentAccount = User::with('financeContact')->find($booking->user_id);
        // Step 2: Check if a voucher already exists to avoid duplicates
        $existingVoucher = Voucher::where('narration', 'LIKE', "%Refund payment for {$booking->booking_unique_id}%")->first();
        if ($existingVoucher) {
            return; // Do nothing if a voucher already exists
        }

        // Step 3: Create Voucher
        if ($refundAmount > 0) {
            $jVoucher = Voucher::create([
                'v_no' => Voucher::generateVoucherNumber($voucherTypeId),
                'v_date' => now(),
                'voucher_type_id' => $voucherTypeId,
                'narration' => 'Refund payment for ' . $booking->booking_unique_id,
                'total_debit' => $refundAmount,
                'total_credit' => $refundAmount,
                'currency' => $userCurrency,
                'reference_id' => $booking->booking_unique_id
            ]);

            // Step 4: Fetch Account Codes
            // $salesAccount = ChartOfAccount::where('account_code', '4000001')->first(); // Sales Revenue
            // $walletAccount = ChartOfAccount::where('account_code', '2000101')->first(); // Wallet
            // $bankAccount = ChartOfAccount::where('account_code', '1010201')->first(); // Card Payment (Bank)
            // $accountsReceivable = ChartOfAccount::where('account_code', '1100101')->first(); // Pay Later

            // Fetch required account codes
            $salesAccount = ChartOfAccount::where('account_code', $agentAccount->financeContact->sales_account_code ?? '401010100001')->first(); // Sales Revenue
            $accountsReceivable = ChartOfAccount::where('account_code', $agentAccount->financeContact->account_code ?? '101040100457')->first(); // Pay Later
            $exchange_Rate = CurrencyService::getCurrencyRate($userCurrency);
            $usd_rate = CurrencyService::convertCurrencyToUsd($userCurrency, $refundAmount);
            $pkr_rate = round(CurrencyService::convertCurrencyFromUsd('PKR', $usd_rate), 2);

            // Step 5: Determine Voucher Posting Based on Payment Type
            if ($booking->booking_status === 'cancelled' && $accountsReceivable) {

                VoucherDetail::create([
                    'voucher_id' => $jVoucher->id,
                    'account_code' => $accountsReceivable->account_code,
                    'narration' => 'Received Refund payment for ' . $booking->booking_unique_id,
                    'debit_pkr' => 0,
                    'credit_pkr' => $pkr_rate,
                    'debit_forn' => 0,
                    'credit_forn' => $refundAmount,
                    'exchange_rate' => $exchange_Rate->rate,
                    'currency' => $userCurrency,
                ]);

                VoucherDetail::create([
                    'voucher_id' => $jVoucher->id,
                    'account_code' => $salesAccount->account_code,
                    'narration' => 'Refund payment for ' . $booking->booking_unique_id,
                    'debit_pkr' => $pkr_rate,
                    'credit_pkr' => 0,
                    'debit_forn' => $refundAmount,
                    'credit_forn' => 0,
                    'exchange_rate' => $exchange_Rate->rate,
                    'currency' => $userCurrency,
                ]);


            }

        }
    }

}
