<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class GentingRate extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'genting_hotel_id',
        'genting_package_id',
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

    public function gentingHotel()
    {
        return $this->belongsTo(GentingHotel::class);
    }
    public function gentingPackage()
    {
        return $this->belongsTo(GentingPackage::class);
    }
}
