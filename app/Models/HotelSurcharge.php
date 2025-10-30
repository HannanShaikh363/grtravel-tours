<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HotelSurcharge extends Model
{
    public function hotel()
    {
        return $this->belongsTo(ContractualHotel::class, 'hotel_id');
    }
}
