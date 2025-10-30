<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ToursBooking extends Model
{
    use HasFactory;
    protected $table = 'tours_booking';

    protected $fillable = [
        'id',
        'location_id',
        'passenger_full_name',
        'passenger_email_address',
        'passenger_contact_number',
        'parent_id',
        'tour_id',
        'user_id',
        'booking_date',
        'tour_date',
        'total_cost',
        'flight_number',
        'iata_code',
        'airline_iata',
        'flight_arrival_time',
    ];

    public function users()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function tours()
    {
        // Explode the comma-separated string into an array of tour IDs
        $tourIds = explode(',', $this->tour_id); // This converts '3,2' into [3, 2]

        // Fetch and return all related tours based on those IDs
        return Tour::whereIn('id', $tourIds)->get(); // Returns a collection of Tour models
    }

    public function tourPackage()
    {
        return $this->belongsTo(Tour::class, 'parent_id', 'id');
    }
}
