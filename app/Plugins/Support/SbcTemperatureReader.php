<?php

namespace App\Plugins\Support;

class SbcTemperatureReader
{
    public function read(): ?float
    {
        foreach ([
            fn () => $this->readSysfs('/sys/class/thermal/thermal_zone0/temp'),
            fn () => $this->readSysfs('/etc/armbianmonitor/datasources/soctemp'),
            fn () => $this->readCommand('/usr/bin/vcgencmd measure_temp'),
        ] as $reader) {
            $temperature = $reader();

            if ($temperature !== null) {
                return $temperature;
            }
        }

        return null;
    }

    private function readSysfs(string $path): ?float
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $raw = trim((string) @file_get_contents($path));

        if ($raw === '' || ! is_numeric($raw)) {
            return null;
        }

        $value = (float) $raw;

        if ($value >= 1000) {
            $value /= 1000;
        }

        return round($value, 1);
    }

    private function readCommand(string $command): ?float
    {
        $output = @shell_exec($command);

        if (! is_string($output) || trim($output) === '') {
            return null;
        }

        if (! preg_match('/(-?\d+(?:\.\d+)?)/', $output, $matches)) {
            return null;
        }

        return round((float) $matches[1], 1);
    }
}
