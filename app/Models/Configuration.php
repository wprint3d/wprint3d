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

        if ($config) {
            return $config->value;
        }

        $config = config("system.defaults.{$key}");

        if ($config !== null) { return $config; }

        return $default;
    }
}
