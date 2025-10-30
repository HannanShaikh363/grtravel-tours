<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TourBooking extends Model
{
    use HasFactory;
    protected $table = 'tour_bookings';

    protected $fillable = [
        'id',
        'location_id',
        'booking_id',
        'passenger_full_name',
        'passenger_email_address',
        'passenger_contact_number',
        'phone_code',
        'tour_rate_id',
        'user_id',
        'booking_date',
        'tour_date',
        'tour_time',
        'pickup_time',
        'pickup_address',
        'dropoff_address',
        'special_request',
        'total_cost',
        'currency',
        'number_of_adults',
        'number_of_children',
        'seating_capacity',
        'child_ages',
        'approved',
        'sent_approval',
        'email_sent',
        'created_by_admin',
        'tour_name',
        'package',
        'number_of_infants',
        'booking_slot',
        'type',
        'nationality_id',
        'driver_id',
        'tour_destination_id',
    ];

    public function users()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function tour()
    {
        return $this->belongsTo(Tour::class, 'tour_rate_id', 'id');
    }

    public function tourDestination()
    {
        return $this->belongsTo(TourDestination::class, 'tour_destination_id', 'id');
    }
    public function booking()
    {

        return $this->belongsTo(Booking::class, 'booking_id');
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }
}
