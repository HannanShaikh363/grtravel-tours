<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TourRate extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'tour_destination_id',
        'package',
        'currency',
        'price',
        'remarks',
        'seating_capacity',
        'luggage_capacity',
        'effective_date',
        'expiry_date',
        'sharing'
    ];

    /**
     * Relationship: A TourRate belongs to a TourDestination.
     */
    public function tourDestination()
    {
        return $this->belongsTo(TourDestination::class);
    }
}
