<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CurrencyRate extends  Model
{


    protected $fillable = [
        'base_currency',
        'target_currency',
        'rate',
        'rate_date'
    ];

    protected $casts = [
        'rate_date' => 'date'
    ];

    public function scopeLatestRates($query, $baseCurrency)
    {
        return $query->where('base_currency', $baseCurrency)
            ->where('rate_date', now()->format('Y-m-d'));
    }
}
