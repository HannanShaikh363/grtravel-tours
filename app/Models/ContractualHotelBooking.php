<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractualHotelBooking extends Model
{
    protected $fillable = [
        'id',
        'country_id',
        'city_id',
        'rate_id',
        'hotel_id',
        'booking_id',
        'user_id',
        'hotel_name',
        'check_in',
        'check_out',
        'total_cost',
        'currency',
        'room_type',
        'number_of_rooms',
        'room_capacity',
        'extra_beds_adult',
        'extra_beds_child',
        'extra_amount_adult_bed',
        'extra_amount_child_bed',
        'confirmation_id',
        'reservation_id',
        'approved',
        'sent_approval',
        'email_sent',
        'created_by_admin'
    ];
    public function cityRelation()
    {
        return $this->belongsTo(City::class, 'city_id', 'id');
    }
     public function countryRelation()
    {
        return $this->belongsTo(Country::class, 'country_id', 'id');
    }
    public function contractualRate()
    {
        return $this->belongsTo(ContractualHotelRate::class, 'rate_id', 'id');
    }
    public function contractualHotel()
    {
        return $this->belongsTo(ContractualHotel::class, 'hotel_id', 'id');
    }
    public function booking()
    {

        return $this->belongsTo(Booking::class, 'booking_id');
    }
    public function roomDetails()
    {
        // One booking can have many room details
        return $this->hasMany(ContractualRoomDetail::class, 'booking_id');
    }
    public function users()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

}
