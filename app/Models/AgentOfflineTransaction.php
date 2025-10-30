<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgentOfflineTransaction extends Model
{
    protected $table = 'agent_offline_transactions';

    protected $fillable = ['amount', 'user_id','agent_id', 'transaction_type','effective_date','expiration_date','active','booking_id'];


    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }



}
