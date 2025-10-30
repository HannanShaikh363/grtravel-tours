<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransportInsurance extends Model
{

    protected $table = 'transports_insurance';

    protected $fillable = [
        'transport_id',
        'insurance_company_name',
        'insurance_policy_number',
        'insurance_expiry_date',

    ];
    public function transport()
    {
        return $this->belongsTo(Transport::class, 'transport_id', 'id');
    }

}
