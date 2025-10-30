<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgentCompanyFinance extends Model
{
    use HasFactory;

    // Define the table name if it doesn't follow Laravel's pluralization convention
    protected $table = 'agent_company_finance';

    // Define the fillable fields to allow mass assignment
    protected $fillable = [
        'agent_id',
        'company_id',
        'finance_id'
    ];

    // Define relationships (if needed)
    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function finance()
    {
        return $this->belongsTo(FinanceContact::class, 'finance_id');
    }
}
