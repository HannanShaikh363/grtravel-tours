<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{

    use HasFactory;

    protected $fillable = [
        'reference_id',
        'module',
        'payment_method',
        'amount',
        'transaction_type',
        'user_id',
        'transaction_date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
