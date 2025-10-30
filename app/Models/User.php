<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Lab404\Impersonate\Models\Impersonate;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, Impersonate;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'username',
        'phone',
        'password',
        'designation',
        'type',
        'credit_limit',
        'mobile',
        'fax',
        'preferred_currency',
        'credit_limit_currency',
        'created_by_admin',
        'approved',
        'email_verification_token',
        'phone_code',
        'agent_code',
    ];
    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function company()
    {
        return $this->hasOne(Company::class, 'user_id', 'id');
    }

    public function financeContact()
    {
        return $this->hasOne(FinanceContact::class, 'user_id', 'id');
    }

    public function agentPricingAdjustment()
    {
        return $this->hasMany(AgentPricingAdjustment::class, 'agent_id', 'id');
    }

    public static function generateUniqueAgentCode()
    {
        do {
            $code = random_int(100000, 999999); // Generate a 6-digit random number
        } while (self::where('agent_code', $code)->exists()); // Ensure the code is unique

        return $code;
    }

    public function wishlistItems()
    {
        return $this->hasMany(Wishlist::class);
    }

    public function isAdmin()
    {
        return $this->type === 'admin';
    }

    public function getEffectiveCreditLimit()
    {
        if ($this->type === 'staff') {
            $agent = self::where('type', 'agent')
                ->where('agent_code', $this->agent_code)
                ->first();
            if ($agent) {
                return [
                    'credit_limit_currency' => $agent->credit_limit_currency,
                    'credit_limit' => round($agent->credit_limit, 2),
                ];
            }
        }

        return [
            'credit_limit_currency' => $this->credit_limit_currency,
            'credit_limit' => round($this->credit_limit, 2),
        ];
    }

    public function getOwner()
    {
        if ($this->type === 'staff') {
            return User::where('agent_code', $this->agent_code)
                ->whereIn('type', ['agent', 'admin'])
                ->first() ?? $this; // fallback to self if not found
        }

        return $this;
    }

}
