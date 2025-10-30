<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookingOffer extends Model
{
    use HasFactory;

    protected $fillable = ['booking_id', 'admin_id', 'user_id', 'hotel_id', 'package_id', 'rate_id', 'price', 'status'];

    public function booking() {
        return $this->belongsTo(Booking::class);
    }

    public function admin() {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function user() {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function hotel() {
        return $this->belongsTo(GentingHotel::class);
    }

    public function package() {
        return $this->belongsTo(GentingPackage::class);
    }

    public function rate() {
        return $this->belongsTo(GentingRate::class);
    }
}
