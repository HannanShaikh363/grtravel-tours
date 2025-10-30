<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssignedDiscountVoucher extends Model
{
    use HasFactory;
    protected $fillable = [
        'discount_voucher_id',
        'user_id',
        'booking_id',
        'discount_applied',
    ];

    public function voucher()
    {
        return $this->belongsTo(DiscountVoucher::class, 'discount_voucher_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
