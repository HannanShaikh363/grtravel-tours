<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Model;


class CancellationPolicies extends Model
{

    protected $table = 'cancellation_policies';
    protected $fillable = ['name', 'description', 'type', 'policy_meta', 'user_id'];


    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the active cancellation policy by type.
     *
     * @param string $type
     * @return CancellationPolicies|null
    */
    public static function getActivePolicyByType(string $type)
    {
        return self::where('active', 1)
            ->where('type', $type)
            ->first();
    }
}
