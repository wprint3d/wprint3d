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
 * Convert any G0 or G1 command, or the output of M114 to XYZE updates.
 *
 * @return array
 */
function movementToXYZE(string $command) : array {
    $position = [];

    $command =
        Str::of( $command )
            ->replaceMatches('/ Count.*/', '') // we don't care about the allocated count (M114)
            ->replace(':', '')                 // M114 returns data split by ":", remove them so that they match what G0 or G1 would look like
            ->replace('ok', '')                // M114 contains the "ok" word, remove it
            ->trim()                           // trim spaces at beginning and end
            ->explode(' ');

    foreach ($command as $argument) {
        if (!isset( $argument[0] )) continue;

        foreach ([ 'X', 'Y', 'Z', 'E' ] as $axis) {
            if ($argument[0] == $axis) {
                $position[ strtolower($axis) ] = Str::replace($axis, '', $argument);
            }
        }
    }

    return $position;
}

?>