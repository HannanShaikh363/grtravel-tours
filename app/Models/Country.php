<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Country extends Model
{

    protected $fillable = ['name','iso2','iso3','phonecode','capital','currency','currency_symbol','currency_code','flag'];

    public function cities()
    {
        return $this->hasMany(City::class);
    }
}
