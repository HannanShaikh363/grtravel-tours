<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QueryLog extends Model
{
    protected $fillable = [
        'sql',
        'bindings',
        'time',
        'user_id',
    ];
}
