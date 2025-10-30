<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgentAddCreditLimit extends Model
{

    protected $table = 'agent_credit_limit_added';

    protected $fillable = ['amount', 'user_id', 'agent_id'];


    public function user()
    {
        return $this->belongsTo(User::class, 'user_id','id');
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id', 'id');
    }
}
