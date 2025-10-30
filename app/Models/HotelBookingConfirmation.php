<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HotelBookingConfirmation extends Model
{
     protected $fillable = ['confirmation_status','confirmation_no','confirmation_note','telephone_no','staff_name'];
}
