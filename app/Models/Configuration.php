<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

use Jenssegers\Mongodb\Eloquent\Model;

class Configuration extends Model
{
    use HasFactory;

    protected $fillable = [ 'key', 'value' ];

    public static function get($key, $default = null) {
        $config = self::where('key', $key)->first();

        if (!$config) { return $default; }

        return $config->value;
    }
}
