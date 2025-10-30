<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transport extends Model
{
    protected $table = 'transports';

    protected $fillable =  [
        'vehicle_make',
        'vehicle_model',
        'vehicle_year_of_manufacture',
        'vehicle_vin',
        'vehicle_license_plate_number',
        'vehicle_color',
        'vehicle_engine_number',
        'vehicle_fuel_type',
        'vehicle_transmission_type',
        'vehicle_body_type',
        'vehicle_seating_capacity',
        'vehicle_luggage_capacity',
        'vehicle_registration_number',
        'package',
        // 'driver_full_name',
        // 'driver_contact_number',
        'user_id',
    ];
    public function fleetBookings()
    {
        return $this->hasMany(FleetBooking::class);
    }

    public function driver()
    {
        return $this->hasMany(TransportDriver::class);
    }

    public function insurance()
    {
        return $this->hasOne(TransportInsurance::class);
    }

    public function rates()
    {
        return $this->belongsToMany(Rate::class,  'rate_transport', 'transport_id', 'rate_id');
    }
}
