<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HotelRoomPassengerDetail extends Model
{
    protected $fillable = [
        'id',
        'room_detail_id',
        'passenger_title',
        'passenger_first_name',
        'passenger_last_name',
        'passenger_email_address',
        'passenger_contact_number',
        'phone_code',
        'nationality_id',
    ];

    public function nationality()
    {
        return $this->belongsTo(Country::class, 'nationality_id');
    }

    public function roomDetail()
    {
        return $this->belongsTo(HotelRoomDetail::class, 'room_detail_id');
    }
}
