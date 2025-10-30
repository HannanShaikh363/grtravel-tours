<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FleetBooking extends Model
{
    use HasFactory;

    protected $table = 'fleet_bookings';

    protected $fillable = [
        'id',
        'transport_id',
        'booking_id',
        'transfer_name',
        'from_location_id',
        'to_location_id',
        'nationality_id',
        'vehicle_seating_capacity',
        'vehicle_luggage_capacity',
        'passenger_title',
        'passenger_full_name',
        'passenger_contact_number',
        'phone_code',
        'passenger_email_address',
        'pick_time',
        'pick_date',
        'agent_id',
        'user_id',
        'booking_date',
        'pickup_date',
        'dropoff_date',
        'rate_id',
        'total_cost',
        'booking_cost',
        'approved',
        'depart_flight_number',
        'arrival_flight_number',
        'depart_flight_date',
        'return_depart_flight_date',
        'arrival_flight_date',
        'return_arrival_flight_date',
        'flight_departure_time',
        'return_flight_departure_time',
        'return_flight_arrival_time',
        'return_depart_flight_number',
        'return_arrival_flight_number',
        'flight_arrival_time',
        'hours',
        'journey_type',
        'vehicle_make',
        'vehicle_model',
        'meeting_point',
        'airport_type',
        'return_pickup_date',
        'return_pickup_time',
        'created_by_admin',
        'currency',
        'arrival_terminal',
        'return_arrival_terminal',
        'sent_email',
        'driver_id',
        'package',
    ];

    public function transport()
    {
        return $this->belongsTo(Transport::class, 'transport_id');
    }


    public function getRate()
    {
        return $this->belongsTo(Rate::class, 'rate_id');
    }

    public function fromLocation()
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation()
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }


    public function booking()
    {

        return $this->belongsTo(Booking::class, 'booking_id');
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    public function hotelLocations()
    {

        return $this->belongsToMany(TransferHotel::class, 'transfer_hotel', 'booking_id', 'hotel_location_id')
            ->withPivot('latitude', 'longitude', 'hotel_name', 'created_at', 'updated_at');
    }

    public function transferBookingHotel()
    {
        return $this->hasMany(TransferHotel::class, 'booking_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
