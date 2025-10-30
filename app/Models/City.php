<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'country_id','tbo_code', 'rezlive_code'];

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public static function findByCountry($country_id)
    {
        return self::where('country_id', $country_id)->pluck('name', 'id');
    }

    public static function findByCountryAndCityName($country_id,$city_name)
    {

        return self::where('country_id', $country_id)->where('name', 'LIKE', "%{$city_name}%")->get(['name', 'id']);
    }
}
