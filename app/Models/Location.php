<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    protected $table = 'locations';

    protected $fillable = [
        'name',
        'code',
        'country_id',
        'city_id',
        'latitude',
        'longitude',
        'location_type',
        'location_meta',
        'user_id',
    ];
    public function users()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function city()
    {
        return $this->belongsTo(City::class, 'city_id', 'id');
    }

    public function country()
    {
        return $this->belongsTo(Country::class, 'country_id', 'id');
    }

    public function bookings()
    {
        return $this->belongsToMany(FleetBooking::class, 'transfer_hotel')
            ->withPivot('location_id');
    }

    public function meetingPoints()
    {
        return $this->hasMany(MeetingPoint::class,'location_id','id');
    }
}
