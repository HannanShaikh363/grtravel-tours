<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractualHotelRate extends Model
{
    protected $fillable = [
        'hotel_id',
        'room_type',
        'weekdays_price',
        'weekend_price',
        'currency',
        'entitlements',
        'no_of_beds',
        'room_capacity',
        'effective_date',
        'expiry_date',
        'images'
    ];
    public function contractualHotel()
    {
        return $this->belongsTo(ContractualHotel::class, 'hotel_id');
    }

}
