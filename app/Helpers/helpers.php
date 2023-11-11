<?php

use App\Enums\FormatterCommands;

use App\Jobs\PrintGcode;
use App\Jobs\SaveSnapshot;

use App\Models\Configuration;

use Illuminate\Log\Logger;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

use Illuminate\Support\Str;

/**
 * tryToWaitForMapper
 * 
 * Try to wait for the device mapper to shut down.
 *
 * @param  mixed $log
 * @return bool Whether we had to wait for the mapper 
 */
function tryToWaitForMapper(?Logger $log = null): bool {
    $didWait = false;

    while (
        Cache::get(
            key:     config('cache.mapper_busy_key'),
            default: false
        )
    ) {
        $didWait = true;

        if ($log) {
            $log->debug('Waiting for the mapper to shutdown...');
        }

        sleep(1);
    }

    return $didWait;
}

function millis() : float {
    return round(
        num:        hrtime(true) / 1000000,
        precision:  2,
        mode:       PHP_ROUND_HALF_DOWN
    );
}

function nowHuman() : string {
    return date('Y-m-d H:i:s');
}

function containsUTF8(string $string) : bool {
    for ($index = 0; $index < strlen($string); $index++) {
        if (mb_check_encoding($string[ $index ], 'UTF-8')) {
            return true;
        }
    }

    return false;
}

function containsNonUTF8(string $string) : bool {
    for ($index = 0; $index < strlen($string); $index++) {
        if (!mb_check_encoding($string[ $index ], 'UTF-8')) {
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

/**
 * convertColorSwapToSequence
 *
 * @param  string $command          - the original M600 sentence
 * @param  string $lastMovementMode - the last movement mode (either G90 or G91)
 * 
 * @return array
 */
function convertColorSwapToSequence(string $command, string $lastMovementMode): array {
    // $extruders = [];

    $retractionDistance     = null;
    $loadLength             = null;
    $resumeTemperature      = null;
    $resumeRetractionLength = null;

    $changeLocation = [
        'X' => PrintGcode::COLOR_SWAP_DEFAULT_X,
        'Y' => PrintGcode::COLOR_SWAP_DEFAULT_Y,
        'Z' => PrintGcode::COLOR_SWAP_DEFAULT_Z
    ];

    $command = Str::of($command);

    foreach ($command->explode(' ') as $argument) {
        if (!isset( $argument[0] )) continue;

        switch ($argument[0]) {
            // case 'B': not implemented yet
            case 'E': $retractionDistance       = Str::replaceFirst('E', '', $argument); break;
            case 'L': $loadLength               = Str::replaceFirst('L', '', $argument); break;
            case 'R': $resumeTemperature        = Str::replaceFirst('R', '', $argument); break;
            // case 'T': $extruders[]              = Str::replaceFirst('T', '', $argument); break; - what am I even supposed to do with this index?
            case 'U': $resumeRetractionLength   = Str::replaceFirst('U', '', $argument); break;
            case 'X': $changeLocation['X']      = Str::replaceFirst('X', '', $argument); break;
            case 'Y': $changeLocation['Y']      = Str::replaceFirst('Y', '', $argument); break;
            case 'Z': $changeLocation['Z']      = Str::replaceFirst('Z', '', $argument); break;
        }
    }

    if (!$retractionDistance) {
        $retractionDistance = PrintGcode::COLOR_SWAP_DEFAULT_RETRACTION_LENGTH;
    }

    if (!$loadLength) {
        $loadLength = PrintGcode::COLOR_SWAP_DEFAULT_LOAD_LENGTH;
    }

    // if (!$extruders) {
    //     $extruders = array_keys( $statistics['extruders'] );
    // }

    if (!$resumeRetractionLength) {
        $resumeRetractionLength = PrintGcode::COLOR_SWAP_DEFAULT_RETRACTION_LENGTH;
    }

    $appendedCommands = [];

    $appendedCommands[] = 'G91';                                                                             // set relative movement mode
    $appendedCommands[] = 'M300 S885 P150';                                                                  // for now, we're just gonna beep once instead of reconstructing the whole sequence
    $appendedCommands[] = "G0 E-{$retractionDistance}";                                                      // retract before moving to change location

    // move to change location
    $appendedCommands[] = 'G90';                                                                             // set absolute movement mode
    $appendedCommands[] = "G0 X-{$changeLocation['X']} Y-{$changeLocation['Y']} Z{$changeLocation['Z']} ;" . FormatterCommands::IGNORE_POSITION_CHANGE;

    $appendedCommands[] = 'M0';                                                                              // wait for filament change

    if ($resumeTemperature) {
        $appendedCommands[] = "M109 S{$resumeTemperature}";                                                  // wait for temperature before resuming
    }

    $appendedCommands[] = 'G91';                                                                             // set relative movement mode
    $appendedCommands[] = 'G92 E0';                                                                          // reset (E)xtruder to 0
    $appendedCommands[] = "G0 E{$loadLength} F" . PrintGcode::COLOR_SWAP_EXTRUDER_FEED_RATE;                 // load the new filament
    $appendedCommands[] = 'G92 E0';                                                                          // reset (E)xtruder to 0 (again)
    $appendedCommands[] = "G0 E-{$resumeRetractionLength}";                                                  // retract a little bit

    // get back on top of the printed object
    $appendedCommands[] = 'G90';                                                                             // set absolute movement mode
    $appendedCommands[] = ";" . FormatterCommands::GO_BACK;                                                  // move back to previous location
    $appendedCommands[] = "G0 E{$resumeRetractionLength}";                                                   // de-retract
    $appendedCommands[] = ";" . FormatterCommands::RESTORE_EXTRUDER;                                         // restore the previous extruder travel value

    if ($lastMovementMode) {
        $appendedCommands[] = $lastMovementMode;                                                             // reset last movement mode (if defined)
    }

    return $appendedCommands;
}

function getGCode(string $line) {
    $line = Str::of( $line );

    // strip comments
    if ($line->startsWith(';') || !$line->length()) return null;

    $line = $line->replaceMatches('/;.*/', '')->trim();

    // avoid empty lines
    if (!$line->length()) return null;

    return $line;
}

function readStreamLine(mixed $stream, ?int $maxLength = null): string {
    $line = '';

    while (
        (
            $char = stream_get_contents(
                stream: $stream,
                length: 1
            )
        ) !== false
        &&
        !feof( $stream )
    ) {
        if ($maxLength === null || strlen($line) < $maxLength) {
            $line .= $char;
        }

        if ($char == PHP_EOL) break;
    }

    return $line;
}

function machineUUID(): ?string {
    $uuid = Cache::get('machineUUID');

    if (!$uuid) {
        $uuid = Configuration::get('machineUUID');

        if ($uuid) {
            Cache::put('machineUUID', $uuid);
        }
    }

    return $uuid;
}

function getSnapshotsPrefix(string $fileName, string $jobUID, int $index, bool $requiresLibCamera) {
    return
        SaveSnapshot::SNAPSHOTS_DIRECTORY
        . '/' .
        basename($fileName)
        . '_' .
        $jobUID
        . '_' .
        $index
        . '_' .
        ($requiresLibCamera ? '1' : '0');
}

function enabled(string $featureName) {
    return config("features.{$featureName}") ?? false;
}

function getAppRevision() {
    $version = '';

    try {
        $version = Storage::disk('internal')->get('app_ver');
    } catch (Exception $exception) { /* Do nothing */ }

    $version = trim( $version );

    if (empty( $version )) {
        return 'unknown revision';
    }

    return 'rev. ' . $version;
}
}

?>