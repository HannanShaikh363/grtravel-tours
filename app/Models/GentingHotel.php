<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\GentingAddBreakFast;
use Illuminate\Database\Eloquent\SoftDeletes;

class GentingHotel extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'hotel_name',
        'location_id',
        'descriptions',
        'images',
        'facilities',
        'others',
        'closing_day',
        'hotel_code',
        'genting_addtional_id'
    ];

    /**
     * Relationship: A TourDestination has many TourRates.
     */
    public function gentingRate()
    {
        return $this->hasMany(GentingRate::class);
    }


    public function location()
    {
        return $this->belongsTo(Location::class);
    }
    public function gentingAdditional()
    {
        return $this->belongsTo(GentingAddBreakFast::class);
    }

    public function breakfastAddition()
    {
        return $this->hasOne(GentingAddBreakFast::class, 'hotel_id');
    }
}
