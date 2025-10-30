<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Models\Voucher;
use App\Models\VoucherDetail;
use App\Models\VoucherType;
use App\Models\ChartOfAccount;
use App\Services\CurrencyService;
use App\Traits\LogsModelEvents;

class Booking extends Model
{
    use LogsModelEvents;
    protected $fillable = [
        'agent_id',
        'user_id',
        'amount',
        'conversion_rate',
        'booking_date',
        'created_by_admin',
        'currency',
        'service_date',
        'deadline_date',
        'booking_status',
        'booking_type',
        'booking_type_id',
        'payment_type',
        'subtotal',
        'tax_percent',
        'tax_amount',
        'original_rate',
        'original_rate_currency',
        'original_rate_conversion',
        'net_rate',
        'net_rate_currency',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id'); // Make sure agent_id is the correct foreign key
    }

    public function fleetbooking()
    {
        return $this->hasOne(FleetBooking::class, 'id', 'booking_type_id');
    }

    public function tourbooking()
    {
        return $this->hasOne(TourBooking::class, 'id', 'booking_type_id');
    }
    public function gentingbooking()
    {
        return $this->hasOne(GentingBooking::class, 'id', 'booking_type_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($booking) {
            $booking->booking_unique_id = $booking->booking_unique_id ?? Str::uuid();
        });

        static::created(function ($booking) {
            $prefixes = [
                'transfer' => 'GRT',
                'tour' => 'GRTU',
                'flight' => 'GRF',
                'hotel' => 'GRH',
                'genting_hotel' => 'GRGH',
                'contractual_hotel' => 'GRCH',
                'ticket' => 'GRTK',
            ];

            $prefix = $prefixes[$booking->booking_type] ?? 'GRX';
            $date = now()->format('ymd');
            $bookingId = str_pad($booking->id, 4, '0', STR_PAD_LEFT);
            $count = Booking::whereDate('created_at', today())->count() + 1;

            $booking->booking_unique_id = "{$prefix}-{$date}-" . $bookingId;
            $booking->save();

            // Step 2: Voucher Posting Logic

            if ($booking->booking_status === 'vouchered' && $booking->amount > 0) {
                self::createVoucherForBooking($booking);
            }


        });


        static::updated(function ($booking) {
            if ($booking->isDirty('booking_status') && $booking->booking_status === 'vouchered') {
                self::createVoucherForBooking($booking);
            }
        });
    }


    private static function createVoucherForBooking($booking)
    {
        // Step 1: Get Voucher Type ID
        $voucherType = VoucherType::where('code', 'SV-J')->first();
        $voucherTypeId = $voucherType ? $voucherType->id : 1;


        // Step 2: Check if a voucher already exists to avoid duplicates
        $existingVoucher = Voucher::where('narration', 'LIKE', "%Booking payment for {$booking->booking_unique_id}%")->first();
        if ($existingVoucher) {
            return; // Do nothing if a voucher already exists
        }

        // Step 3: Create Voucher
        if ($booking->amount > 0) {
            $jVoucher = Voucher::create([
                'v_no' => Voucher::generateVoucherNumber($voucherTypeId),
                'v_date' => now(),
                'voucher_type_id' => $voucherTypeId,
                'narration' => 'Booking payment for ' . $booking->booking_unique_id,
                'total_debit' => $booking->amount,
                'total_credit' => $booking->amount,
                'currency' => $booking->currency,
                'reference_id' => $booking->booking_unique_id
            ]);

            // Step 4: Fetch Account Codes
            // $salesAccount = ChartOfAccount::where('account_code', '4000001')->first(); // Sales Revenue
            // $walletAccount = ChartOfAccount::where('account_code', '2000101')->first(); // Wallet
            // $bankAccount = ChartOfAccount::where('account_code', '1010201')->first(); // Card Payment (Bank)
            // $accountsReceivable = ChartOfAccount::where('account_code', '1100101')->first(); // Pay Later

            // Fetch required account codes
            $user = User::where('id', $booking->user_id)->first();
            if ($user->type == 'staff') {

                if (empty($user->agent_code)) {
                    $user = User::where('type', 'admin')->first();
                }else{
                    // Fetch users with type 'agent' who have the same agent_code
                    $user = User::where('type', 'agent')
                        ->where('agent_code', $user->agent_code) // Make sure the agent code matches
                        ->first();
                }

            }
            $salesAccount = ChartOfAccount::where('account_code', $user->financeContact->sales_account_code)->first(); // Sales Revenue
            $walletAccount = ChartOfAccount::where('account_code', $user->financeContact->account_code)->first(); // Wallet Account
            $bankAccount = ChartOfAccount::where('account_code', $user->financeContact->account_code)->first(); // Bank Account (Card Payment)
            $accountsReceivable = ChartOfAccount::where('account_code', $user->financeContact->account_code)->first(); // Pay Later
            $exchange_Rate = CurrencyService::getCurrencyRate($booking->currency);
            $usd_rate = CurrencyService::convertCurrencyToUsd($booking->currency, $booking->amount);
            $pkr_rate = round(CurrencyService::convertCurrencyFromUsd('PKR', $usd_rate), 2);

            // Step 5: Determine Voucher Posting Based on Payment Type
            if ($booking->payment_type === 'pay_later' && $accountsReceivable) {

                VoucherDetail::create([
                    'voucher_id' => $jVoucher->id,
                    'account_code' => $accountsReceivable->account_code,
                    'narration' => 'Booking due payment for ' . $booking->booking_unique_id,
                    'debit_pkr' => $pkr_rate,
                    'credit_pkr' => 0,
                    'debit_forn' => $booking->amount,
                    'credit_forn' => 0,
                    'exchange_rate' => $exchange_Rate->rate,
                    'currency' => $booking->currency,
                ]);

                // VoucherDetail::create([
                //     'voucher_id' => $jVoucher->id,
                //     'account_code' => $salesAccount->account_code,
                //     'narration' => 'Booking due payment for ' . $booking->booking_unique_id,
                //     'debit_pkr' => $pkr_rate,
                //     'credit_pkr' => 0,
                //     'debit_forn' => $booking->amount,
                //     'credit_forn' => 0,
                //     'exchange_rate' => $exchange_Rate->rate,
                //     'currency' => $booking->currency,
                // ]);

            } elseif ($booking->payment_type === 'wallet' && $walletAccount) {

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

            } elseif ($booking->payment_type === 'card' && $bankAccount) {

                $voucherType = VoucherType::where('code', 'GV-J')->first();
                $voucherTypeId = $voucherType ? $voucherType->id : 1;
                $brvVoucher = Voucher::create([
                    'v_no' => Voucher::generateVoucherNumber($voucherTypeId),
                    'v_date' => now(),
                    'voucher_type_id' => $voucherTypeId, // BRV Type
                    'narration' => 'Bank receipt for card payment of booking ID ' . $booking->booking_unique_id,
                    'total_debit' => $booking->amount,
                    'total_credit' => $booking->amount,
                    'currency' => $booking->currency,
                    'reference_id' => $booking->booking_unique_id
                ]);

                VoucherDetail::create([
                    'voucher_id' => $brvVoucher->id,
                    'account_code' => $bankAccount->account_code,
                    'narration' => 'Card payment received for booking ID ' . $booking->booking_unique_id,
                    'debit_pkr' => $pkr_rate,
                    'credit_pkr' => 0,
                    'debit_forn' => $booking->amount,
                    'credit_forn' => 0,
                    'exchange_rate' => $exchange_Rate->rate,
                    'currency' => $booking->currency,
                ]);

                if ($accountsReceivable) {
                    VoucherDetail::create([
                        'voucher_id' => $jVoucher->id,
                        'account_code' => $accountsReceivable->account_code,
                        'narration' => 'Clearing Accounts Receivable for booking ID ' . $booking->id,
                        'debit_pkr' => 0,
                        'credit_pkr' => $booking->amount,
                        'exchange_rate' => $exchange_Rate->rate,
                        'debit_forn' => 0,
                        'credit_forn' => $booking->amount,
                        'currency' => $booking->currency,
                    ]);
                }
            }

            // Step 6: Credit Revenue Account (Common for all)
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
