<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractualRoomDetail extends Model
{
    protected $fillable = [
        'room_no',
        'booking_id',
        'number_of_adults',
        'number_of_children',
        'extra_bed_for_adult',
        'extra_bed_for_child',
        'child_ages'
    ];
    public function booking()
    {
        return $this->belongsTo(ContractualHotelBooking::class, 'booking_id'); // 'booking_id' is the foreign key in RoomDetails table
    }

    public function passengers()
    {
        // One room detail can have many passengers
        return $this->hasMany(ContractualRoomPassengerDetail::class, 'room_detail_id');
    }


    public function nationality()
    {
        return $this->belongsTo(Country::class, 'nationality_id');
    }
}
