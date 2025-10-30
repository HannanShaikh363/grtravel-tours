<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GentingBooking extends Model
{
    use HasFactory;

    protected $table = 'genting_bookings';
    protected $fillable = [
        'id',
        'location_id',
        'booking_id',
        'genting_rate_id',
        'genting_hotel_id',
        'user_id',
        'check_in',
        'check_out',
        'total_cost',
        'currency',
        'number_of_adults',
        'number_of_children',
        'room_capacity',
        'child_ages',
        'approved',
        'sent_approval',
        'email_sent',
        'created_by_admin',
        'hotel_name',
        'package',
        'room_type',
        'number_of_rooms',
        'confirmation_id', 
        'reservation_id',
        'additional_adults',
        'additional_children',
        'additional_adult_price',
        'additional_child_price'
    ];

    public function users()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function gentingRate()
    {
        return $this->belongsTo(GentingRate::class, 'genting_rate_id', 'id');
    }

    public function gentingHotel()
    {
        return $this->belongsTo(GentingHotel::class, 'genting_hotel_id', 'id');
    }

    public function booking()
    {

        return $this->belongsTo(Booking::class, 'booking_id');
    }

    // Define the relationship to RoomDetails
    public function roomDetails()
    {
        return $this->hasMany(GentingRoomDetail::class, 'booking_id'); // 'booking_id' is the foreign key in RoomDetails table
    }
}
