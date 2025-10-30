<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinanceContact extends Model
{

    protected $fillable = [
        'user_id',
        'company_id',
        'account_name',
        'account_email',
        'account_contact',
        'phone_code_finance',
        'sales_account_code',
        'account_code'

    ];
}
