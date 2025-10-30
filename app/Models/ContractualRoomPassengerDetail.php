<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractualRoomPassengerDetail extends Model
{
     protected $fillable = [
        'room_detail_id',
        'passenger_title',
        'passenger_full_name',
        'phone_code',
        'passenger_contact_number',
        'passenger_email_address',
        'nationality_id'
     ];
     public function nationality()
    {
        return $this->belongsTo(Country::class, 'nationality_id');
    }
    public function roomDetail()
    {
        return $this->belongsTo(ContractualRoomDetail::class, 'room_detail_id');
    }
}
   