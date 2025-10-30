<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Driver extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'car_no', 'phone_number', 'phone_code'];

    public function fleetBookings()
    {
        return $this->hasMany(FleetBooking::class, 'driver_id');
    }
}
