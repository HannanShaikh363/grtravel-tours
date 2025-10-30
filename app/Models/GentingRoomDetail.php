<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GentingRoomDetail extends Model
{
    use HasFactory;

    protected $table = 'genting_room_details';
    protected $fillable = [
        'id',
        'room_no',
        'booking_id',
        'number_of_adults',
        'number_of_children',
        'child_ages',
        'extra_bed_for_child',
    ];

    public function booking()
    {
        return $this->belongsTo(GentingBooking::class, 'booking_id'); // 'booking_id' is the foreign key in RoomDetails table
    }

    public function passengers()
    {
        return $this->hasMany(GentingRoomPassengerDetail::class, 'room_detail_id');
    }

    public function nationality()
    {
        return $this->belongsTo(Country::class, 'nationality_id');
    }

}
