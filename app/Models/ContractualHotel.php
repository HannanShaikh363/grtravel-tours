<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractualHotel extends Model
{
    
    protected $fillable = [
        'hotel_name',
        'city_id',
        'country_id',
        'description',
        'images',
        'property_amenities',
        'room_features',
        'room_types',
        'important_info',
        'extra_bed_child',
        'extra_bed_adult',
        'currency'
    ];
    public function cityRelation()
    {
        return $this->belongsTo(City::class, 'city_id', 'id');
    }
     public function countryRelation()
    {
        return $this->belongsTo(Country::class, 'country_id', 'id');
    }



}
