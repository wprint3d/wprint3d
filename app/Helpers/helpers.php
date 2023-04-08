<?php

use Illuminate\Log\Logger;

use Illuminate\Support\Facades\Cache;

use Illuminate\Support\Str;

function tryToWaitForMapper(?Logger $log = null) {
    while (
        Cache::get(
            key:     config('cache.mapper_busy_key'),
            default: false
        )
    ) {
        if ($log) {
            $log->debug('Waiting for the mapper to shutdown...');
        }

        sleep(1);
    }
}

function millis() : float {
    return floor(microtime(true) * 1000);
}

function nowHuman() : string {
    return now()->format('Y-m-d H:i:s');
}

function containsUTF8(string $string) : bool {
    for ($index = 0; $index < strlen($string); $index++) {
        if (mb_check_encoding($string[ $index ], 'UTF-8')) {
            return true;
        }
    }

    return false;
}

/**
 * movementToXYZ
 * 
 * Convert any G0 or G1 command, or the output of M114 to XYZ updates.
 *
 * @return array
 */
function movementToXYZ(string $command) : array {
    $position = [];

    $command =
        Str::of( $command )
            ->replaceMatches('/ Count.*/', '') // we don't care about the allocated count (M114)
            ->replace(':', '')                 // M114 returns data split by ":", remove them so that they match what G0 or G1 would look like
            ->explode(' ');

    foreach ($command as $argument) {
        if (!isset( $argument[0] )) continue;

        switch ($argument[0]) {
            case 'X': $position['x'] = (float) Str::replace('X', '', $argument); break;
            case 'Y': $position['y'] = (float) Str::replace('Y', '', $argument); break;
            case 'Z': $position['z'] = (float) Str::replace('Z', '', $argument); break;
        }
    }

    return $position;
}

?>