<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Model;

class CancellationDeductions extends Model
{
    protected $table = 'cancellation_deductions';
    protected $fillable = ['deduction', 'service_id', 'service_type','user_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }


}
