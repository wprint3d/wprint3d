<?php

namespace App\Models;

use App\Enums\DataType;

use App\Exceptions\InitializationException;

use Illuminate\Database\Eloquent\Factories\HasFactory;

use MongoDB\Laravel\Eloquent\Model;

class Configuration extends Model
{
    use HasFactory;

    protected $fillable = [ 'key', 'value' ];

    public static function get($key, $default = null) {
        $config = self::where('key', $key)->first();

        if (!$config) { return $default; }

        return $config->value ?? $config->default ?? $default;
    }

    public static function set($key, $value): bool {
        $config = self::where('key', $key)->first();

        if (!$config) {
            throw new InitializationException("No such configuration");
        }

        if (isset($config->writeable) && !$config->writeable) {
            throw new InitializationException("The configuration key {$key} is not writeable");
        }

        if ($config->type == DataType::BOOLEAN && !is_bool($value)) {
            throw new InitializationException("A boolean value is expected for this configuration key");
        } else if ($config->type == DataType::INTEGER && !is_int($value)) {
            throw new InitializationException("An integer value is expected for this configuration key");
        } else if ($config->type == DataType::FLOAT && !is_float($value)) {
            throw new InitializationException("A float value is expected for this configuration key");
        } else if ($config->type == DataType::STRING && !is_string($value)) {
            throw new InitializationException("A string value is expected for this configuration key");
        }

        $config->value = $value;
        $config->save();

        return true;
    }

}
