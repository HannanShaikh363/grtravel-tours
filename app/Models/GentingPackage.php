<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GentingPackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'package',
        'days',
        'nights',
    ];

    public function gentingRate()
    {
        return $this->hasMany(GentingRate::class);
    }
}
