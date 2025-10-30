<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{


    protected $fillable = [

        'city_id',
        'country_id',
        'agent_name',
        'address',
        'agent_number',
        'zip',
        'agent_website',
        'iata_number',
        'iata_status',
        'nature_of_business',
        'logo',
        'user_id',
        'phone_code_company',
        'certificate'
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
}
