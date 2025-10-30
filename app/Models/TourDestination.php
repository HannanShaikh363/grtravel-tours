<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TourDestination extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'location_id',
        'name',
        'description',
        'highlights',
        'important_info',
        'images',
        'hours',
        'highlights',
        'important_info',
        'closing_day',
        'closing_start_date',
        'closing_end_date',
        'time_slots',
        'ticket_currency',
        'adult',
        'child',
        'on_request',
        'sharing',
        'ticket_title',
    ];

    /**
     * Relationship: A TourDestination has many TourRates.
     */
    public function tourRates()
    {
        return $this->hasMany(TourRate::class);
    }


    /**
     * Relationship: A TourDestination belongs to a Location.
     */
    public function location()
    {
        return $this->belongsTo(Location::class);
    }
}
