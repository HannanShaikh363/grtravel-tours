<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GentingRoomPassengerDetail extends Model
{
    protected $fillable = [
        'id',
        'room_detail_id',
        'passenger_title',
        'passenger_full_name',
        'passenger_email_address',
        'passenger_contact_number',
        'phone_code',
        'nationality_id',
    ];

    public function nationality()
    {
        return $this->belongsTo(Country::class, 'nationality_id');
    }

    public function roomDetail()
    {
        return $this->belongsTo(GentingRoomDetail::class, 'room_detail_id');
    }

    public function getTravellerTypeAttribute()
{
    $childTitles = ['Child'];
    $adultTitles = ['Mr.', 'Ms.', 'Mrs.'];

    if (in_array($this->passenger_title, $adultTitles)) {
        return 'Adult';
    } elseif (in_array($this->passenger_title, $childTitles)) {
        $childAges = json_decode($this->room->child_ages ?? '[]', true) ?: [];
        // NOTE: We can't track child index here easily,
        // so just return 'Child' without age or enhance logic if needed
        return 'Child';
    } else {
        return $this->passenger_title ?? 'N/A';
    }
}
}
