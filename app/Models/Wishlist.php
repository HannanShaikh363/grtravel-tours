<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wishlist extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'rate_id', 'type', 'booking_date'];

    // Relation to User
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Relation to Product
    public function rate()
    {
        return $this->belongsTo(Rate::class);
    }

    public function tourRate()
    {
        return $this->belongsTo(TourRate::class);
    }
}
