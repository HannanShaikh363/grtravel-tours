<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VoucherRedemption extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'voucher_id',
        'booking_id',
        'discount_amount',
        'redeemed_at',
    ];

    protected $casts = [
        'redeemed_at' => 'datetime',
        'discount_amount' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function voucher()
    {
        return $this->belongsTo(DiscountVoucher::class, 'voucher_id');
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
