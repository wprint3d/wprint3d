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

    private static function createFromDefaults(string $key): ?self
    {
        $defaults = config('system.defaults', []);
        $default = $defaults[$key] ?? null;

        if ($default === null) {
            return null;
        }

        $configuration = new self();
        $configuration->key         = $key;
        $configuration->value       = $default['value'];
        $configuration->default     = $default['value'];
        $configuration->hint        = $default['hint'];
        $configuration->type        = $default['type'];
        $configuration->section     = $default['section'];
        $configuration->description = $default['description'];
        $configuration->visible     = $default['visible'] ?? true;
        $configuration->writeable   = $default['writeable'] ?? true;
        $configuration->enum        = $default['enum'] ?? null;
        $configuration->save();

        return $configuration;
    }

    public static function get($key, $default = null) {
        $config = self::where('key', $key)->first();

        if (!$config) { return $default; }

        return $config->value ?? $config->default ?? $default;
    }

    public static function set($key, $value, $overrideWriteable = false): bool {
        $config = self::where('key', $key)->first();

        if (!$config) {
            $config = self::createFromDefaults($key);
        }

        if (!$config) {
            throw new InitializationException("No such configuration");
        }

        if (
            isset($config->writeable) && !$config->writeable
            &&
            !$overrideWriteable
        ) { throw new InitializationException("The configuration key {$key} is not writeable"); }

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
