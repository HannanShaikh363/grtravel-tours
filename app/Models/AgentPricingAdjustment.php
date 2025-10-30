<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgentPricingAdjustment extends Model
{
    protected $table = 'agent_pricing_adjustments';

    protected $fillable = ['percentage', 'user_id', 'agent_id', 'percentage_type', 'transaction_type', 'effective_date', 'expiration_date', 'active'];


    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id', 'id');
    }

    public static function getCurrentAdjustmentRate($agentId, $type = null, $active = 1)
    {
        $query = self::where('agent_id', $agentId);

        if ($type !== null) {
            $query->where('transaction_type', $type);
        }

        if ($active !== null) {
            $query->where('active', $active);
        }

        return $query->get();
        // return self::where('agent_id', $agentId)->get();
    }
}
