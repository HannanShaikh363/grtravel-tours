<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'v_no', 'v_date', 'voucher_type_id', 'cheque_no', 'cheque_date', 
        'narration', 'total_debit', 'total_credit','currency','reference_id'
    ];

    public function type()
    {
        return $this->belongsTo(VoucherType::class, 'voucher_type_id');
    }

    public function details()
    {
        return $this->hasMany(VoucherDetail::class);
    }

    public static function generateVoucherNumber($voucherTypeId)
    {
        $voucherType = VoucherType::findOrFail($voucherTypeId);
        $prefix = $voucherType->code; 
        $lastVoucher = self::where('voucher_type_id', $voucherTypeId)
            ->latest('id')
            ->first();

        $newNumber = $lastVoucher ? ((int) substr($lastVoucher->v_no, -5)) + 1 : 1;
        $sequenceNumber = str_pad($newNumber, 5, '0', STR_PAD_LEFT);
        return "{$prefix}-{$sequenceNumber}";
    }

}
