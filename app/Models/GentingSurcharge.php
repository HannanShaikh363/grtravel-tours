<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GentingSurcharge extends Model
{
    use HasFactory;
    protected $fillable = ['genting_hotel_id', 'surcharges'];

    public function hotel()
    {
        return $this->belongsTo(GentingHotel::class, 'genting_hotel_id');
    }
    
}
