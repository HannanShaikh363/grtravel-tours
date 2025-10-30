<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tour extends Model
{
    use HasFactory;
    protected $table = 'tours';
    protected $fillable = [
        'id',
        'name',
        'package',
        'location_name',
        'location_id',
        'price',
        'currency',
        'seating_capacity',
        'luggage_capacity',
        'adult',
        'child',
        'hours',
        'effective_date',
        'expiry_date',
        'description',
        'remarks',
        'highlights',
        'important_info',
        'images',
        'closing_day',
        
    ];

    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id', 'id');
    }
}
