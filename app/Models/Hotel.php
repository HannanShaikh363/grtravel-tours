<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\City;

class Hotel extends Model
{
    use HasFactory;

    protected $fillable = [
        "id", 
        "hotel_name", 
        "city_id", 
        "rezlive_hotel_code", 
        "tbo_hotel_code", 
        "created_at", 
        "updated_at"
    ];

    /**
     * Relationship: A TourDestination has many TourRates.
     */
    // public function hotelRate()
    // {
    //     return $this->hasMany(HotelRate::class);
    // }


    // public function location()
    // {
    //     return $this->belongsTo(Location::class);
    // }

    public function city()
    {
        return $this->belongsTo(City::class);
    }
}
