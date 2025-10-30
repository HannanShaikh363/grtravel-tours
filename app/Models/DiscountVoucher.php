<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DiscountVoucher extends Model
{
    use HasFactory;
   protected $fillable = [
        'code',
        'type',
        'value',
        'currency',
        'valid_from',
        'valid_until',
        'usage_limit',
        'used_count',
        'per_user_limit',
        'min_booking_amount',
        'max_discount_amount',
        'applicable_to',
        'is_public',
        'status',
        'created_by',
    ];

    protected $casts = [
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'applicable_to' => 'array',
        'is_public' => 'boolean',
    ];

    /**
     * Creator relationship (admin user).
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
