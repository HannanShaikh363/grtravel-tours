<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeetingPoint extends Model
{
    protected $table = 'meeting_point';

    protected $fillable = ['location_id', 'meeting_point_name', 'terminal', 'airport_areas', 'meeting_point_desc', 'meeting_point_type', 'meeting_point_attachment', 'active'];


    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
