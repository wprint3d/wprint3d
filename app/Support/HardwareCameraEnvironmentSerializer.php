<?php

namespace App\Support;

use Illuminate\Support\Str;

class HardwareCameraEnvironmentSerializer
{
    public static function serialize(array $camera): string
    {
        $camera = CameraRuntimeMetadata::normalize($camera);

        $lines = [];

        foreach ($camera as $key => $value) {
            if (! is_scalar($value)) {
                continue;
            }

            if ($value === null) {
                $value = 'null';
            } elseif (is_bool($value)) {
                $value = $value ? 1 : 0;
            }

            $lines[] = Str::of($key)->snake()->upper().'="'.((string) $value).'"';
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
