<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AgentOfflineTransaction;
use App\Models\AgentPricingAdjustment;
use App\Models\AssignedDiscountVoucher;
use App\Models\Booking;
use App\Models\DiscountVoucher;
use App\Models\DiscountVoucherUser;
use App\Models\HotelBooking;
use App\Models\ContractualHotelBooking;
use App\Models\Voucher;
use App\Models\VoucherDetail;
use App\Models\VoucherRedemption;
use App\Models\VoucherType;
use App\Models\ChartOfAccount;
use App\Models\FleetBooking;
use App\Models\GentingBooking;
use App\Models\TourBooking;
use App\Models\User;
use App\Services\BookingService;
use App\Services\CurrencyService;
use App\Services\GentingService;
use App\Services\ContractualHotelService;
use App\Services\RezliveHotelService;
use App\Services\TourService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use ProtoneMedia\Splade\Facades\Toast;

class OfflinePaymentController extends Controller
{

    public function offlineTransaction(Request $request)
    {
        $booking = Booking::findOrFail($request->booking_id);
        $bookingUser = User::findOrFail($booking->user_id);

        if ($bookingUser->type === 'agent') {
            $user = $bookingUser;
        } elseif ($bookingUser->type === 'staff') {
            $user = User::whereIn('type', ['agent', 'admin'])
                ->where('agent_code', $bookingUser->agent_code)
                ->firstOrFail();
        } else {
            // Fallback or throw if neither agent nor staff
            abort(400, 'Invalid booking owner');
        }

        $adjustment = AgentPricingAdjustment::where('agent_id', $user->id)->first();
        $amount = 0;
        // Check if the adjustment exists

        if ($adjustment) {
            // Get the percentage adjustment and transaction type
            $percentage = $adjustment->percentage; // Example: 10 (for 10%)
            $transactionType = $adjustment->transaction_type; // 'increase' or 'decrease'
            $amount = $booking->amount;
            // Calculate the adjustment
            if ($transactionType == 'transfer') {
                // Increase the amount by the percentage
                $amount += ($amount * $percentage / 100);
            }
        }
        if ($user->type !== 'admin') {
            $amount = $booking->amount;
            $currency = $booking->currency;

            if ($request->get('voucher_code') != null) {
                $voucher = DiscountVoucher::where('code', $request->voucher_code)
                    ->where(function ($query) {
                        $query->whereNull('usage_limit') // unlimited
                            ->orWhereColumn('used_count', '<', 'usage_limit');
                    })
                    ->where('status', 'active') // optional: ensure it's not disabled
                    ->first();

                if (!$voucher) {
                    return back()->with('error', 'Invalid Voucher Code');
                }

                $now = now();

                if (
                    ($voucher->valid_from && $voucher->valid_from > $now) ||
                    ($voucher->valid_until && $voucher->valid_until < $now)
                ) {
                    return back()->with('error', 'Invalid or expired voucher code.');
                }

                if ($voucher) {
                    $discountPrice = 0;
                    if ($voucher->type === 'fixed') {
                        $discountPrice = $voucher->value;
                        if ($voucher->currency != $currency) {
                            $discountPrice = CurrencyService::convertCurrencyTOUsd($voucher->currency, $discountPrice);
                            $discountPrice = CurrencyService::convertCurrencyFromUsd($currency, $discountPrice);
                        }
                    } elseif ($voucher->type === 'percentage') {
                        $discountPrice = ($voucher->value / 100) * $amount; // Convert booking amount to USD
                        if (!is_null($voucher->max_discount_amount) && $voucher->max_discount_amount < $discountPrice) {
                            $discountPrice = $voucher->max_discount_amount;
                        }
                    }

                    $amount = max(0, $amount - $discountPrice);
                }
            }

            $limit = auth()->user()->getEffectiveCreditLimit();
            $usercreditLimit = $limit['credit_limit'];
            if ($currency == $limit['credit_limit_currency']) {
                if ($limit['credit_limit'] < $amount) {
                    Toast::title('Insufficient credit limit')
                        ->danger()
                        ->rightBottom()
                        ->autoDismiss(5);
                    return back()->with('error', 'Insufficient credit limit');
                }
            }
            if ($currency != $limit['credit_limit_currency']) {
                if ($amount = CurrencyService::convertCurrencyTOUsd($currency, $amount)) {
                    $usercreditLimit = CurrencyService::convertCurrencyToUsd($limit['credit_limit_currency'], $usercreditLimit);
                    if ($usercreditLimit < $amount) {
                        Toast::title('Insufficient credit limit')
                            ->danger()
                            ->rightBottom()
                            ->autoDismiss(5);
                        return back()->with('error', 'Insufficient credit limit');
                    }
                }
            }
            if ($currency == $limit['credit_limit_currency'] || $limit['credit_limit_currency'] == 'USD') {
                $deducted = $usercreditLimit - $amount;
            }
            if ($currency != $limit['credit_limit_currency'] && $limit['credit_limit_currency'] != 'USD') {
                $deducted = $usercreditLimit - $amount;

                $deducted = CurrencyService::convertCurrencyFromUsd($limit['credit_limit_currency'], $deducted);
            }
            $booking->payment_type = 'wallet';
            $booking->booking_status = 'vouchered';

            $booking->save();
            $user->update(['credit_limit' => $deducted]);

             if ($request->get('voucher_code') != null && $voucher) {
                DB::beginTransaction();

                try {
                    // Increase voucher global used count
                    $voucher->increment('used_count');

                    // Update or create user voucher usage
                    $userVoucher = DiscountVoucherUser::firstOrNew([
                        'user_id' => $user->id,
                        'voucher_id' => $voucher->id,
                    ]);

                    $userVoucher->usage_count = ($userVoucher->usage_count ?? 0) + 1;
                    $userVoucher->assigned_at = $userVoucher->assigned_at ?? now();
                    $userVoucher->save();

                    // Save redemption record
                    VoucherRedemption::updateOrCreate([
                        'user_id' => $user->id,
                        'voucher_id' => $voucher->id,
                        'booking_id' => $booking->id,
                        'discount_amount' => round($discountPrice, 2),
                        'redeemed_at' => now(),
                    ]);

                    DB::commit();

                } catch (\Exception $e) {
                    DB::rollBack();
                    return back()->with('error', 'Failed to apply voucher. Please try again.');
                }
            }
        }
        $booking->payment_type = 'wallet';
        $booking->booking_status = 'vouchered';

        $booking->save();
        // $this->createVoucherForCancelBooking($booking);
        AgentOfflineTransaction::firstOrCreate(
            [
                'booking_id' => $booking->id,
            ],
            [
                'amount' => $amount,
                'transaction_type' => $booking->booking_type,
                'user_id' => $booking->user->id,
            ]
        );

        $fleetBooking = FleetBooking::with(['driver', 'fromLocation.country'])->where('id', $booking->booking_type_id)->first();
        // dd($fleetBooking);


        // Data preparation
        $dropOffName = $request->input('drop_off_name');
        $pickUpName = $request->input('pick_up_name');
        $is_updated = null;
        app(BookingService::class)->sendVoucherEmail($request, $fleetBooking, $dropOffName, $pickUpName, $is_updated);

        // Determine success or failure based on some logic
        $isSuccessful = true; // Replace this with actual payment processing logic

        if ($isSuccessful) {
            return back()->with('success', 'Payment has been done successfully!');
        } else {
            return back()->with('error', 'Payment failed. Please try again.');
        }
    }

    public function tourOfflineTransaction(Request $request)
    {
        $booking = Booking::findOrFail($request->booking_id);
        $bookingUser = User::findOrFail($booking->user_id);

        if ($bookingUser->type === 'agent') {
            $user = $bookingUser;
        } elseif ($bookingUser->type === 'staff') {
            $user = User::whereIn('type', ['agent', 'admin'])
                ->where('agent_code', $bookingUser->agent_code)
                ->firstOrFail();
        } else {
            // Fallback or throw if neither agent nor staff
            abort(400, 'Invalid booking owner');
        }
        // Now use the correct agent/admin wallet
        $adjustment = AgentPricingAdjustment::where('agent_id', $user->id)->first();
        $amount = 0;
        // Check if the adjustment exists
        if ($adjustment) {
            // Get the percentage adjustment and transaction type
            $percentage = $adjustment->percentage; // Example: 10 (for 10%)
            $transactionType = $adjustment->transaction_type; // 'increase' or 'decrease'
            $amount = $booking->amount;
            // Calculate the adjustment
            if ($transactionType == 'tour') {
                // Increase the amount by the percentage
                $amount += ($amount * $percentage / 100);
            }
        }
        if ($user->type !== 'admin') {

            $amount = $booking->amount;
            $currency = $booking->currency;

            if ($request->get('voucher_code') != null) {
                $voucher = DiscountVoucher::where('code', $request->voucher_code)
                    ->where(function ($query) {
                        $query->whereNull('usage_limit') // unlimited
                            ->orWhereColumn('used_count', '<', 'usage_limit');
                    })
                    ->where('status', 'active') // optional: ensure it's not disabled
                    ->first();

                if (!$voucher) {
                    return back()->with('error', 'Invalid Voucher Code');
                }

                $now = now();

                if (
                    ($voucher->valid_from && $voucher->valid_from > $now) ||
                    ($voucher->valid_until && $voucher->valid_until < $now)
                ) {
                    return back()->with('error', 'Invalid or expired voucher code.');
                }

                if ($voucher) {
                    $discountPrice = 0;
                    if ($voucher->type === 'fixed') {
                        $discountPrice = $voucher->value;
                        if ($voucher->currency != $currency) {
                            $discountPrice = CurrencyService::convertCurrencyTOUsd($voucher->currency, $discountPrice);
                            $discountPrice = CurrencyService::convertCurrencyFromUsd($currency, $discountPrice);
                        }
                    } elseif ($voucher->type === 'percentage') {
                        $discountPrice = ($voucher->value / 100) * $amount; // Convert booking amount to USD
                        if (!is_null($voucher->max_discount_amount) && $voucher->max_discount_amount < $discountPrice) {
                            $discountPrice = $voucher->max_discount_amount;
                        }
                    }

                    $amount = max(0, $amount - $discountPrice);
                }
            }

            $limit = $user->getEffectiveCreditLimit();
            $usercreditLimit = $limit['credit_limit'];
            if ($currency == $limit['credit_limit_currency']) {
                if ($limit['credit_limit'] < $amount) {
                    Toast::title('Insufficient credit limit')
                        ->danger()
                        ->rightBottom()
                        ->autoDismiss(5);
                    return back()->with('error', 'Insufficient credit limit');
                }
            }
            if ($currency != $limit['credit_limit_currency']) {
                if ($amount = CurrencyService::convertCurrencyTOUsd($currency, $amount)) {
                    $usercreditLimit = CurrencyService::convertCurrencyToUsd($limit['credit_limit_currency'], $usercreditLimit);
                    if ($usercreditLimit < $amount) {
                        Toast::title('Insufficient credit limit')
                            ->danger()
                            ->rightBottom()
                            ->autoDismiss(5);
                        return back()->with('error', 'Insufficient credit limit');
                    }
                }
            }
            if ($currency == $limit['credit_limit_currency'] || $limit['credit_limit_currency'] == 'USD') {
                $deducted = $usercreditLimit - $amount;
            }
            if ($currency != $limit['credit_limit_currency'] && $limit['credit_limit_currency'] != 'USD') {
                $deducted = $usercreditLimit - $amount;

                $deducted = CurrencyService::convertCurrencyFromUsd($limit['credit_limit_currency'], $deducted);

            }
            $booking->payment_type = 'wallet';
            $booking->booking_status = 'vouchered';
            $booking->save();
            $user->update(['credit_limit' => $deducted]);

            if ($request->get('voucher_code') != null && $voucher) {
                DB::beginTransaction();

                try {
                    // Increase voucher global used count
                    $voucher->increment('used_count');

                    // Update or create user voucher usage
                    $userVoucher = DiscountVoucherUser::firstOrNew([
                        'user_id' => $user->id,
                        'voucher_id' => $voucher->id,
                    ]);

                    $userVoucher->usage_count = ($userVoucher->usage_count ?? 0) + 1;
                    $userVoucher->assigned_at = $userVoucher->assigned_at ?? now();
                    $userVoucher->save();

                    // Save redemption record
                    VoucherRedemption::updateOrCreate([
                        'user_id' => $user->id,
                        'voucher_id' => $voucher->id,
                        'booking_id' => $booking->id,
                        'discount_amount' => round($discountPrice, 2),
                        'redeemed_at' => now(),
                    ]);

                    DB::commit();

                } catch (\Exception $e) {
                    DB::rollBack();
                    return back()->with('error', 'Failed to apply voucher. Please try again.');
                }
            }
        }

        $user->update(['credit_limit' => $deducted]);

        $booking->payment_type = 'wallet';
        $booking->booking_status = 'vouchered';

        $booking->save();
        AgentOfflineTransaction::firstOrCreate(
            [
                'booking_id' => $booking->id,
            ],
            [
                'amount' => $amount,
                'transaction_type' => $booking->booking_type,
                'user_id' => $booking->user->id,
            ]
        );

        $tourBooking = TourBooking::with('location.country')->where('id', $booking->booking_type_id)->first();
        // dd($fleetBooking);
        $is_updated = null;

        app(TourService::class)->sendVoucherEmail($request, $tourBooking, $is_updated);

        // Determine success or failure based on some logic
        $isSuccessful = true; // Replace this with actual payment processing logic

        if ($isSuccessful) {
            return back()->with('success', 'Payment has been done successfully!');
        } else {
            return back()->with('error', 'Payment failed. Please try again.');
        }
    }

    public function gentingOfflineTransaction(Request $request)
    {
        $booking = Booking::findOrFail($request->booking_id);
        $bookingUser = User::findOrFail($booking->user_id);

        if ($bookingUser->type === 'agent') {
            $user = $bookingUser;
        } elseif ($bookingUser->type === 'staff') {
            $user = User::whereIn('type', ['agent', 'admin'])
                ->where('agent_code', $bookingUser->agent_code)
                ->firstOrFail();
        } else {
            // Fallback or throw if neither agent nor staff
            abort(400, 'Invalid booking owner');
        }

        // $user = auth()->user();
        // $booking = Booking::where('id', $booking_id)->first();

        // $adjustment = AgentPricingAdjustment::where('agent_id', $user->id)->first();
        $amount = 0;
        $discountPrice = 0;
        // // Check if the adjustment exists
        // if ($adjustment) {
        //     // Get the percentage adjustment and transaction type
        //     $percentage = $adjustment->percentage; // Example: 10 (for 10%)
        //     $transactionType = $adjustment->transaction_type; // 'increase' or 'decrease'
        //     $amount = $booking->amount;
        //     // Calculate the adjustment
        //     if ($transactionType == 'tour') {
        //         // Increase the amount by the percentage
        //         $amount += ($amount * $percentage / 100);
        //     }
        // }
        if ($user->type !== 'admin') {
            $amount = str_replace(',', '', $booking->amount);
            $currency = $booking->currency;
            if ($request->get('voucher_code') != null) {
                $max_booking_cap = 0;
                $voucher = DiscountVoucher::where('code', $request->voucher_code)
                    ->where(function ($query) {
                        $query->whereNull('usage_limit') // unlimited
                            ->orWhereColumn('used_count', '<', 'usage_limit');
                    })
                    ->where('status', 'active') // optional: ensure it's not disabled
                    ->first();

                if (!$voucher) {
                    return back()->with('error', 'Invalid Voucher Code');
                }

                if (!is_null($voucher->min_booking_amount) && $amount <= $voucher->min_booking_amount) {
                    return back()->with('error', 'Booking amount does not meet the minimum required for this voucher.');
                }

                // Get user's usage from pivot table
                $userUsage = DiscountVoucherUser::where('user_id', $booking->user_id)
                    ->where('voucher_id', $voucher->id)
                    ->first();

                if ($voucher->per_user_limit !== null) {
                    $usageCount = $userUsage?->usage_count ?? 0;

                    if ($usageCount >= $voucher->per_user_limit) {
                        return back()->with('error', 'Voucher usage limit reached for user.');
                    }
                }

                $now = now();

                if (
                    ($voucher->valid_from && $voucher->valid_from > $now) ||
                    ($voucher->valid_until && $voucher->valid_until < $now)
                ) {
                    return back()->with('error', 'Invalid or expired voucher code.');
                }

                if ($voucher) {

                    if ($voucher->type === 'fixed') {
                        $discountPrice = $voucher->value;
                        if ($voucher->currency != $currency) {
                            $discountPrice = CurrencyService::convertCurrencyTOUsd($voucher->currency, $discountPrice);
                            $discountPrice = CurrencyService::convertCurrencyFromUsd($currency, $discountPrice);
                        }
                    } elseif ($voucher->type === 'percentage') {
                        $discountPrice = ($voucher->value / 100) * $amount; // Convert booking amount to USD
                        if (!is_null($voucher->max_discount_amount) && $discountPrice > $voucher->max_discount_amount) {
                            $discountPrice = $voucher->max_discount_amount;
                        }
                    }
                    $amount = max(0, $amount - $discountPrice);
                }
            }
            $limit = $user->getEffectiveCreditLimit();
            $usercreditLimit = $limit['credit_limit'];
            if ($currency == $limit['credit_limit_currency']) {
                if ($limit['credit_limit'] < $amount) {

                    Toast::title('Insufficient credit limit')
                        ->danger()
                        ->rightBottom()
                        ->autoDismiss(5);
                    return back()->with('error', 'Insufficient credit limit');
                }
            }
            if ($currency != $limit['credit_limit_currency']) {
                if ($amount = CurrencyService::convertCurrencyTOUsd($currency, $amount)) {
                    $usercreditLimit = CurrencyService::convertCurrencyToUsd($limit['credit_limit_currency'], $usercreditLimit);
                    if ($usercreditLimit < $amount) {
                        Toast::title('Insufficient credit limit')
                            ->danger()
                            ->rightBottom()
                            ->autoDismiss(5);
                        return back()->with('error', 'Insufficient credit limit');
                    }
                }
            }
            if ($currency == $limit['credit_limit_currency'] || $limit['credit_limit_currency'] == 'USD') {
                $deducted = $usercreditLimit - $amount;
            }
            if ($currency != $limit['credit_limit_currency'] && $limit['credit_limit_currency'] != 'USD') {
                $deducted = $usercreditLimit - $amount;

                $deducted = CurrencyService::convertCurrencyFromUsd($limit['credit_limit_currency'], $deducted);
            }
            $booking->payment_type = 'wallet';
            $booking->booking_status = 'vouchered';

            $booking->save();
            $user->update(['credit_limit' => $deducted]);
            if ($request->get('voucher_code') != null && $voucher) {
                DB::beginTransaction();

                try {
                    // Increase voucher global used count
                    $voucher->increment('used_count');

                    // Update or create user voucher usage
                    $userVoucher = DiscountVoucherUser::firstOrNew([
                        'user_id' => $user->id,
                        'voucher_id' => $voucher->id,
                    ]);

                    $userVoucher->usage_count = ($userVoucher->usage_count ?? 0) + 1;
                    $userVoucher->assigned_at = $userVoucher->assigned_at ?? now();
                    $userVoucher->save();

                    // Save redemption record
                    VoucherRedemption::updateOrCreate([
                        'user_id' => $user->id,
                        'voucher_id' => $voucher->id,
                        'booking_id' => $booking->id,
                        'discount_amount' => round($discountPrice, 2),
                        'redeemed_at' => now(),
                    ]);

                    DB::commit();

                } catch (\Exception $e) {
                    DB::rollBack();
                    return back()->with('error', 'Failed to apply voucher. Please try again.');
                }
            }
        }


        $booking->payment_type = 'wallet';
        $booking->booking_status = 'vouchered';

        $booking->save();

        AgentOfflineTransaction::firstOrCreate(
            [
                'booking_id' => $booking->id,
            ],
            [
                'amount' => $amount,
                'transaction_type' => $booking->booking_type,
                'user_id' => $booking->user->id,
            ]
        );

        $gentingBooking = GentingBooking::with('location.country')->where('id', $booking->booking_type_id)->first();
        // dd($fleetBooking);
        $is_updated = null;
        app(GentingService::class)->sendVoucherEmail($request, $gentingBooking, $is_updated);

        // Determine success or failure based on some logic
        $isSuccessful = true; // Replace this with actual payment processing logic

        if ($isSuccessful) {
            return back()->with('success', 'Payment has been done successfully!');
        } else {
            return back()->with('error', 'Payment failed. Please try again.');
        }
    }
    public function contractualHotelOfflineTransaction(Request $request)
    {
        $booking = Booking::findOrFail($request->booking_id);
        $bookingUser = User::findOrFail($booking->user_id);

        if ($bookingUser->type === 'agent') {
            $user = $bookingUser;
        } elseif ($bookingUser->type === 'staff') {
            $user = User::whereIn('type', ['agent', 'admin'])
                ->where('agent_code', $bookingUser->agent_code)
                ->firstOrFail();
        } else {
            // Fallback or throw if neither agent nor staff
            abort(400, 'Invalid booking owner');
        }

        // $user = auth()->user();
        // $booking = Booking::where('id', $booking_id)->first();

        // $adjustment = AgentPricingAdjustment::where('agent_id', $user->id)->first();
        $amount = 0;
        $discountPrice = 0;
        // // Check if the adjustment exists
        // if ($adjustment) {
        //     // Get the percentage adjustment and transaction type
        //     $percentage = $adjustment->percentage; // Example: 10 (for 10%)
        //     $transactionType = $adjustment->transaction_type; // 'increase' or 'decrease'
        //     $amount = $booking->amount;
        //     // Calculate the adjustment
        //     if ($transactionType == 'tour') {
        //         // Increase the amount by the percentage
        //         $amount += ($amount * $percentage / 100);
        //     }
        // }
        if ($user->type !== 'admin') {
            $amount = str_replace(',', '', $booking->amount);
            $currency = $booking->currency;
            if ($request->get('voucher_code') != null) {
                $max_booking_cap = 0;
                $voucher = DiscountVoucher::where('code', $request->voucher_code)
                    ->where(function ($query) {
                        $query->whereNull('usage_limit') // unlimited
                            ->orWhereColumn('used_count', '<', 'usage_limit');
                    })
                    ->where('status', 'active') // optional: ensure it's not disabled
                    ->first();

                if (!$voucher) {
                    return back()->with('error', 'Invalid Voucher Code');
                }

                if (!is_null($voucher->min_booking_amount) && $amount <= $voucher->min_booking_amount) {
                    return back()->with('error', 'Booking amount does not meet the minimum required for this voucher.');
                }

                // Get user's usage from pivot table
                $userUsage = DiscountVoucherUser::where('user_id', $booking->user_id)
                    ->where('voucher_id', $voucher->id)
                    ->first();

                if ($voucher->per_user_limit !== null) {
                    $usageCount = $userUsage?->usage_count ?? 0;

                    if ($usageCount >= $voucher->per_user_limit) {
                        return back()->with('error', 'Voucher usage limit reached for user.');
                    }
                }

                $now = now();

                if (
                    ($voucher->valid_from && $voucher->valid_from > $now) ||
                    ($voucher->valid_until && $voucher->valid_until < $now)
                ) {
                    return back()->with('error', 'Invalid or expired voucher code.');
                }

                if ($voucher) {

                    if ($voucher->type === 'fixed') {
                        $discountPrice = $voucher->value;
                        if ($voucher->currency != $currency) {
                            $discountPrice = CurrencyService::convertCurrencyTOUsd($voucher->currency, $discountPrice);
                            $discountPrice = CurrencyService::convertCurrencyFromUsd($currency, $discountPrice);
                        }
                    } elseif ($voucher->type === 'percentage') {
                        $discountPrice = ($voucher->value / 100) * $amount; // Convert booking amount to USD
                        if (!is_null($voucher->max_discount_amount) && $discountPrice > $voucher->max_discount_amount) {
                            $discountPrice = $voucher->max_discount_amount;
                        }
                    }
                    $amount = max(0, $amount - $discountPrice);
                }
            }
            $limit = $user->getEffectiveCreditLimit();
            $usercreditLimit = $limit['credit_limit'];
            if ($currency == $limit['credit_limit_currency']) {
                if ($limit['credit_limit'] < $amount) {

                    Toast::title('Insufficient credit limit')
                        ->danger()
                        ->rightBottom()
                        ->autoDismiss(5);
                    return back()->with('error', 'Insufficient credit limit');
                }
            }
            if ($currency != $limit['credit_limit_currency']) {
                if ($amount = CurrencyService::convertCurrencyTOUsd($currency, $amount)) {
                    $usercreditLimit = CurrencyService::convertCurrencyToUsd($limit['credit_limit_currency'], $usercreditLimit);
                    if ($usercreditLimit < $amount) {
                        Toast::title('Insufficient credit limit')
                            ->danger()
                            ->rightBottom()
                            ->autoDismiss(5);
                        return back()->with('error', 'Insufficient credit limit');
                    }
                }
            }
            if ($currency == $limit['credit_limit_currency'] || $limit['credit_limit_currency'] == 'USD') {
                $deducted = $usercreditLimit - $amount;
            }
            if ($currency != $limit['credit_limit_currency'] && $limit['credit_limit_currency'] != 'USD') {
                $deducted = $usercreditLimit - $amount;

                $deducted = CurrencyService::convertCurrencyFromUsd($limit['credit_limit_currency'], $deducted);
            }
            $booking->payment_type = 'wallet';
            $booking->booking_status = 'vouchered';

            $booking->save();
            $user->update(['credit_limit' => $deducted]);
            if ($request->get('voucher_code') != null && $voucher) {
                DB::beginTransaction();

                try {
                    // Increase voucher global used count
                    $voucher->increment('used_count');

                    // Update or create user voucher usage
                    $userVoucher = DiscountVoucherUser::firstOrNew([
                        'user_id' => $user->id,
                        'voucher_id' => $voucher->id,
                    ]);

                    $userVoucher->usage_count = ($userVoucher->usage_count ?? 0) + 1;
                    $userVoucher->assigned_at = $userVoucher->assigned_at ?? now();
                    $userVoucher->save();

                    // Save redemption record
                    VoucherRedemption::updateOrCreate([
                        'user_id' => $user->id,
                        'voucher_id' => $voucher->id,
                        'booking_id' => $booking->id,
                        'discount_amount' => round($discountPrice, 2),
                        'redeemed_at' => now(),
                    ]);

                    DB::commit();

                } catch (\Exception $e) {
                    DB::rollBack();
                    return back()->with('error', 'Failed to apply voucher. Please try again.');
                }
            }
        }


        $booking->payment_type = 'wallet';
        $booking->booking_status = 'vouchered';

        $booking->save();

        AgentOfflineTransaction::firstOrCreate(
            [
                'booking_id' => $booking->id,
            ],
            [
                'amount' => $amount,
                'transaction_type' => $booking->booking_type,
                'user_id' => $booking->user->id,
            ]
        );

        $contractualBooking = ContractualHotelBooking::with('countryRelation')->where('id', $booking->booking_type_id)->first();
        // dd($fleetBooking);
        $is_updated = null;
        app(ContractualHotelService::class)->sendVoucherEmail($request, $contractualBooking, $is_updated);

        // Determine success or failure based on some logic
        $isSuccessful = true; // Replace this with actual payment processing logic

        if ($isSuccessful) {
            return back()->with('success', 'Payment has been done successfully!');
        } else {
            return back()->with('error', 'Payment failed. Please try again.');
        }
    }

    public function hotelOfflineTransaction(Request $request)
    {
        $booking_id = $request->booking_id;

        $booking = Booking::where('id', $booking_id)->first();
        $user = $booking->user;

        // $adjustment = AgentPricingAdjustment::where('agent_id', $user->id)->first();
        $amount = 0;

        if ($user->type !== 'admin') {
            $amount = $booking->amount;
            $currency = $booking->currency;
            $usercreditLimit = $user->credit_limit;
            if ($currency == $user->credit_limit_currency) {
                if ($user->credit_limit < $amount) {
                    Toast::title('Insufficient credit limit')
                        ->danger()
                        ->rightBottom()
                        ->autoDismiss(5);
                    return back()->with('error', 'Insufficient credit limit');
                }
            }
            if ($currency != $user->credit_limit_currency) {
                if ($amount = CurrencyService::convertCurrencyTOUsd($currency, $amount)) {
                    $usercreditLimit = CurrencyService::convertCurrencyToUsd($user->credit_limit_currency, $usercreditLimit);
                    if ($usercreditLimit < $amount) {
                        Toast::title('Insufficient credit limit')
                            ->danger()
                            ->rightBottom()
                            ->autoDismiss(5);
                        return back()->with('error', 'Insufficient credit limit');
                    }
                }
            }
            if ($currency == $user->credit_limit_currency || $user->credit_limit_currency == 'USD') {
                $deducted = $usercreditLimit - $amount;
            }
            if ($currency != $user->credit_limit_currency && $user->credit_limit_currency != 'USD') {
                $deducted = $usercreditLimit - $amount;

                $deducted = CurrencyService::convertCurrencyFromUsd($user->credit_limit_currency, $deducted);
            }
            $booking->payment_type = 'wallet';
            $booking->booking_status = 'vouchered';

            $booking->save();
            User::find($user->id)->update(['credit_limit' => $deducted]);
        }

        $booking->payment_type = 'wallet';
        $booking->booking_status = 'vouchered';

        $booking->save();

        AgentOfflineTransaction::firstOrCreate(
            [

                'amount' => $amount,
                'transaction_type' => $booking->booking_type,
                'user_id' => $user->id,

            ],
            ['booking_id' => $booking_id,]


        );

        $hotelBooking = HotelBooking::where('id', $booking->booking_type_id)->first();
        // dd($fleetBooking);
        $is_updated = null;
        app(RezliveHotelService::class)->sendVoucherEmail($request, $hotelBooking, $is_updated);

        // Determine success or failure based on some logic
        $isSuccessful = true; // Replace this with actual payment processing logic

        if ($isSuccessful) {
            return back()->with('success', 'Payment has been done successfully!');
        } else {
            return back()->with('error', 'Payment failed. Please try again.');
        }
    }

    private static function createVoucherForCancelBooking($booking)
    {
        // Step 1: Get Voucher Type ID
        $voucherType = VoucherType::where('code', 'GV-J')->first();
        $voucherTypeId = $voucherType ? $voucherType->id : 1;

        $agentAccount = User::with('financeContact')->find($booking->user_id);
        // Step 2: Check if a voucher already exists to avoid duplicates
        $existingVoucher = Voucher::where('narration', 'LIKE', "%Refund payment for {$booking->booking_unique_id}%")->first();
        if ($existingVoucher) {
            return; // Do nothing if a voucher already exists
        }

        // Step 3: Create Voucher
        if ($booking->amount > 0) {

            $brvVoucher = Voucher::create([
                'v_no' => Voucher::generateVoucherNumber($voucherTypeId),
                'v_date' => now(),
                'voucher_type_id' => $voucherTypeId, // BRV Type
                'narration' => 'receipt for wallet payment of booking ID ' . $booking->booking_unique_id,
                'total_debit' => $booking->amount,
                'total_credit' => $booking->amount,
                'currency' => $booking->currency,
                'reference_id' => $booking->booking_unique_id
            ]);

            // $jVoucher = Voucher::create([
            //     'v_no' => Voucher::generateVoucherNumber($voucherTypeId),
            //     'v_date' => now(),
            //     'voucher_type_id' => $voucherTypeId,
            //     'narration' => 'Wallet payment for ' . $booking->booking_unique_id,
            //     'total_debit' => $refundAmount,
            //     'total_credit' => $refundAmount,
            //     'currency' => $userCurrency,
            //     'reference_id' => $booking->booking_unique_id
            // ]);

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
            if ($booking->booking_status === 'vouchered' && $accountsReceivable) {

                VoucherDetail::create([
                    'voucher_id' => $jVoucher->id,
                    'account_code' => $walletAccount->account_code,
                    'narration' => 'Wallet payment for booking ID ' . $booking->booking_unique_id,
                    'debit_pkr' => $pkr_rate,
                    'credit_pkr' => 0,
                    'exchange_rate' => $exchange_Rate->rate,
                    'debit_forn' => $booking->amount,
                    'credit_forn' => 0,
                    'currency' => $booking->currency,
                ]);

                if ($salesAccount) {
                    VoucherDetail::create([
                        'voucher_id' => $jVoucher->id,
                        'account_code' => $salesAccount->account_code,
                        'narration' => 'Revenue for booking ID ' . $booking->booking_unique_id,
                        'debit_pkr' => 0,
                        'credit_pkr' => $pkr_rate,
                        'debit_forn' => 0,
                        'credit_forn' => $booking->amount,
                        'exchange_rate' => $exchange_Rate->rate,
                        'currency' => $booking->currency,
                    ]);
                }


            }

        }
    }
}
