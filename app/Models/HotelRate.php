<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotelRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'hotel_id',
        'room_type',
        'currency',
        'price',
        'room_capacity',
        'entitlements',
        'images',
        'effective_date',
        'expiry_date',
        'bed_count',
    ];

    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }
    // public function gentingPackage()
    // {
    //     return $this->belongsTo(GentingPackage::class);
    // }
}
