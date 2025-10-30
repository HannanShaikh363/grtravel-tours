<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GentingAddBreakFast extends Model
{

    use HasFactory;

    protected $table = 'genting_add_breakfasts';
    protected $fillable = [
        'id',
        'hotel_id',
        'currency',
        'adult',
        'child'
    ];

    public function hotel()
    {
        return $this->belongsTo(GentingHotel::class, 'hotel_id'); // 'hotel_id' is the foreign key in RoomDetails table
    }
}
