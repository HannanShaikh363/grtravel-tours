<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChartOfAccount extends Model
{
    use HasFactory;

    protected $fillable = ['account_code', 'account_name', 'nature', 'parent_id', 'level', 'type', 'currency', 'status'];

    public function parent()
    {
        return $this->belongsTo(ChartOfAccount::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(ChartOfAccount::class, 'parent_id');
    }

    public static function generateAccountCode($parentId = null)
    {
        if ($parentId) {
            // Get Parent Account
            $parentAccount = self::find($parentId);
            if (!$parentAccount) {
                throw new \Exception('Parent account not found.');
            }
    
            $prefix = $parentAccount->account_code; // First 7 digits (parent)
            $level = $parentAccount->level + 1; // Set level for sub-account
        } else {
            // Generate a new 7-digit parent account code
            $prefix = str_pad(mt_rand(1000000, 9999999), 7, '0', STR_PAD_LEFT);
            $level = 0;
        }
    
        // Find the last sub-account with this prefix
        $lastSubAccount = self::where('account_code', 'LIKE', $prefix . '%')
            ->orderBy('account_code', 'desc')
            ->first();
    
        // Generate next sequence (00001, 00002, ...)
        $nextSequence = $lastSubAccount
            ? (intval(substr($lastSubAccount->account_code, 7)) + 1)
            : 1;
    
        // Format new account code
        $newCode = $prefix . str_pad($nextSequence, 5, '0', STR_PAD_LEFT);
    
        return [
            'account_code' => $newCode,
            'level' => $level
        ];
    }
    
}
