<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransportDriver extends Model
{

    protected $table = 'transports_driver';

    protected $fillable = [
        "driver_full_name",
        "driver_contact_number",
        "driver_email_address",
        "owner_full_name",
        "owner_address",
        "owner_contact_number",
        "owner_email_address",
        "previous_owners",
        "previous_owners_number",
        "special_notes",
        'transport_id',
    ];

    public function transport()
    {
        return $this->belongsTo(Transport::class, 'transport_id', 'id');
    }

}
