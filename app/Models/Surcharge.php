<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Surcharge extends Model
{
    use HasFactory;
    protected $fillable = [
        'id',
        'country_id',
        'surcharge_percentage',
        'start_time',
        'end_time',
    ];

    public function users()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function country()
    {
        return $this->belongsTo(Country::class, 'country_id', 'id');
    }
}
