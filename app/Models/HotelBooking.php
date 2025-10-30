<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotelBooking extends Model
{
    use HasFactory;

    protected $table = 'hotel_bookings';
    protected $fillable = [
        'id',
        'location',
        'booking_id',
        // 'hotel_rate_id',
        // 'hotel_id',
        'booking_type',
        'user_id',
        'check_in',
        'check_out',
        'total_cost',
        'currency',
        'number_of_adults',
        'number_of_children',
        'room_capacity',
        'approved',
        'sent_approval',
        'email_sent',
        'created_by_admin',
        'hotel_name',
        'room_type',
        'number_of_rooms',
        'confirmation_id', 
        'reservation_id',
        'general_remarks',
        'booking_key',
    ];

    public function users()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    // public function gentingRate()
    // {
    //     return $this->belongsTo(GentingRate::class, 'genting_rate_id', 'id');
    // }

    // public function gentingHotel()
    // {
    //     return $this->belongsTo(GentingHotel::class, 'genting_hotel_id', 'id');
    // }

    public function booking()
    {

        return $this->belongsTo(Booking::class, 'booking_id');
    }

    // Define the relationship to RoomDetails
    public function roomDetails()
    {
        return $this->hasMany(HotelRoomDetail::class, 'booking_id'); // 'booking_id' is the foreign key in RoomDetails table
    }
    public function confirmation()
    {
        return $this->hasOne(HotelBookingConfirmation::class, 'booking_id');
    }

}
