<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransferHotel  extends Model
{

    protected $table = 'transfer_hotel';

    protected $fillable = [
        'id',
        'booking_id',
        'hotel_location_id',
        'pickup_hotel_name',
        'dropoff_hotel_name',
        'return_dropoff_hotel_name',
        'return_pickup_hotel_name',
        'longitude',
        'latitude',
        'created_at',
        'updated_at'
    ];

    public function booking()
    {
        return $this->belongsTo(FleetBooking::class, 'booking_id');
    }
}
