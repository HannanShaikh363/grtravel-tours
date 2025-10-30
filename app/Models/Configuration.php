<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Configuration extends Model
{
    protected $fillable = ['group', 'key', 'value'];

    public static function getGroup($group)
    {
        return self::where('group', $group)->pluck('value', 'key')->toArray();
    }

    public static function updateGroup($group, array $data)
    {
        foreach ($data as $key => $value) {
            self::updateOrCreate(
                ['group' => $group, 'key' => $key],
                ['value' => $value]
            );
        }
    }

    public static function getValue($group, $key, $default = null)
    {
        return optional(self::where('group', $group)->where('key', $key)->first())->value ?? $default;
    }
}
