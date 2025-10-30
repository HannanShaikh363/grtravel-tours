<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Rate extends Model
{
    use SoftDeletes;
    protected $table = 'rates';

    protected $fillable = [
        'id',
        'name',
        'from_location_id',
        'to_location_id',
        'transport_id',
        'child_id',
        'vehicle_seating_capacity',
        'vehicle_luggage_capacity',
        'rate',
        'package',
        'currency',
        'rate_type',
        'effective_date',
        'expiry_date',
        'route_type',
        'time_remarks',
        'remarks',
        'hours',
    ];

    public function users()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function fromLocation()
    {
        return $this->belongsTo(Location::class, 'from_location_id', 'id');
    }

    public function toLocation()
    {
        return $this->belongsTo(Location::class, 'to_location_id', 'id');
    }

    public function childLocation()
    {
        return $this->belongsTo(Location::class, 'child_id', 'id');
    }

    public static function findByLocation($id)
    {
        return self::where('from_location_id', $id)->pluck('rate', 'id');
    }

    public function transport()
    {
        return $this->belongsToMany(Transport::class, 'rate_transport', 'rate_id', 'transport_id');
    }

    public function city()
    {
        return $this->belongsTo(City::class, 'city_id', 'id');
    }


    public function country()
    {
        return $this->belongsTo(Country::class, 'country_id', 'id');
    }

    public function wishlists()
    {
        return $this->hasMany(Wishlist::class);
    }
}
