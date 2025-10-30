<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VoucherDetail extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'voucher_id', 'account_code', 'narration', 'debit_pkr', 'credit_pkr', 
        'debit_forn', 'credit_forn', 'currency', 'exchange_rate'
    ];

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    public function account()
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_code', 'account_code');
    }
}