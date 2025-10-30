<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DiscountVoucherUser extends Model
{
    use HasFactory;
     protected $table = 'discount_voucher_users';

    protected $fillable = [
        'user_id',
        'voucher_id',
        'usage_count',
        'assigned_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
    ];

    public function voucher()
    {
        return $this->belongsTo(DiscountVoucher::class, 'voucher_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
