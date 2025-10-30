<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FlightDetail extends Model
{
    use HasFactory;

    protected $table = 'flight_details';

    protected $fillable = [
        'id',
        'tourBooking_id',
        'flight_number',
        'iata_code',
        'arrival_time',
        'departure_time',
        'arrival_time',
        'aircraft_model',
    ];

    public function tourBooking()
    {
        return $this->belongsTo(ToursBooking::class, 'tourBooking_id', 'id');
    }
}
