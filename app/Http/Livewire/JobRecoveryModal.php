<?php

namespace App\Http\Livewire;

use App\Enums\BackupInterval;
use App\Enums\FormatterCommands;
use App\Enums\RecoveryStage;

use App\Events\RecoveryCompleted;
use App\Events\RecoveryProgress;
use App\Events\RecoveryStageChanged;

use App\Jobs\PrintGcode;

use App\Libraries\Serial;

use App\Models\Configuration;
use App\Models\Printer;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

use Illuminate\Support\Str;

use Livewire\Component;

use Exception;

class JobRecoveryModal extends Component
{

    public ?Printer $printer = null;

    public $jobBackupInterval;
    public $jobBackupIntervals;
    public $jobRecoveryStages;

    public $recoveryMainMaxLine = 0;
    public $recoveryAltMaxLine  = 1;

    public $targetRecoveryLine;

    protected $baseFilesDir;

    const RECOVERED_FILE_PREFIX = 'rec';

    const SERIAL_COMMAND_TIMEOUT_SECS = 3;

    const E_AXIS_RETRACT_OFFSET   =  5;   // mm
    const X_AXIS_HOLDING_OFFSET   = -1;   // mm
    const Y_AXIS_HOLDING_OFFSET   = -1;   // mm
    const Z_AXIS_HOLDING_OFFSET   =  1.5; // mm

    const XY_AXIS_WITH_MIN_HOLDING_OFFSET = 1; // mm

    public function boot() {
        $this->baseFilesDir = env('BASE_FILES_DIR');

        $this->printer = Printer::select('available', 'activeFile', 'hasActiveJob', 'lastJobHasFailed', 'lastLine')->find( Auth::user()->activePrinter );

        if ($this->printer && $this->printer->lastLine !== null) {
            if ($this->printer->lastLine > 0) {
                $this->recoveryMainMaxLine = $this->printer->lastLine - 1;
            }

            $this->recoveryAltMaxLine = $this->recoveryMainMaxLine + 1;

            $this->targetRecoveryLine = $this->recoveryMainMaxLine;
        }
    }

    public function skip() {
        $this->printer->activeFile       = null;
        $this->printer->hasActiveJob     = false;
        $this->printer->lastJobHasFailed = false;
        $this->printer->lastLine         = null;
        $this->printer->save();

        $this->emit('refreshActiveFile');
    }

    public function recover() {
        $log = Log::channel('job-recovery');

        $cacheMapperBusyKey = config('cache.mapper_busy_key');

        $jobRestorationTimeoutSecs       = Configuration::get('jobRestorationTimeoutSecs');
        $jobRestorationHomingTemperature = Configuration::get('jobRestorationHomingTemperature');

        $restoreStartTime = time();

        if (
            Cache::get(
                key:     $cacheMapperBusyKey,
                default: false
            )
        ) {
            $this->dispatchBrowserEvent('recoveryFailed', 'we\'re still negotiating a connection with this printer, please try again in a few seconds');

            return;
        }

        try {
            $serial = new Serial(
                fileName:  $this->printer->node,
                baudRate:  $this->printer->baudRate,
                timeout:   self::SERIAL_COMMAND_TIMEOUT_SECS
            );
        } catch (Exception $exception) {
            $log->error(
                __METHOD__ . ': ' . $exception->getMessage() . PHP_EOL .
                $exception->getTraceAsString()
            );

            $this->dispatchBrowserEvent('recoveryFailed', $exception->getMessage());

            return;
        }

        while ($this->printer->activeFile) {
            $this->printer->refresh();

            if (time() - $restoreStartTime > $jobRestorationTimeoutSecs) {
                $this->dispatchBrowserEvent('recoveryTimedOut');

                return;
            }

            try {
                $response = $serial->query('M105');

                $log->debug( __METHOD__ . ": M105: {$response}" );

                if (Str::contains($response, 'ok')) { break; }
            } catch (Exception $exception) {
                $log->warning(
                    __METHOD__ . ': ' . $exception->getMessage() . PHP_EOL .
                    $exception->getTraceAsString()
                );
            }

            sleep(1);
        }

        $warmUpCommands     = [];
        $preSetUpCommands   = [];
        $setUpCommands      = [];
        $postSetUpCommands  = [];

        // default movement mode for Marlin is absolute
        $lastMovementMode = 'G90';

        $previousPosition = [
            'x' => null,
            'y' => null,
            'z' => null,
            'e' => null
        ];

        $absolutePosition = $previousPosition;

        $minLayerPositionXY = [ 'x' => null, 'y' => null ];

        $gcode = Storage::getDriver()->readStream( env('BASE_FILES_DIR') . '/' . $this->printer->activeFile );

        /*
         * This block ensures that the RECOVERED_FILE_PREFIX + time() string
         * combination doesn't get stacked multiple times on a filename. This
         * issue could appear if a print fails multiple times.
         * 
         * Result is 'rec_##########_cube'.
         */
        $newFileName =
            self::RECOVERED_FILE_PREFIX . '_' . time() . // rec_##########
            '_' .
            Str::of( $this->printer->activeFile )->replaceMatches('/' . self::RECOVERED_FILE_PREFIX . '_[0-9]*_/', ''); // 'rec_##########_cube' => 'cube'

        $targetFilePath = Storage::path(
            env('BASE_FILES_DIR')
            . '/' .
            $newFileName
        );

        $targetFile = fopen(
            filename: $targetFilePath,
            mode:     'w' // Create the file, then, open for r/w.
        );

        $lineNumber      = 0;
        $lineNumberCount = 0;

        RecoveryStageChanged::dispatch(
            $this->printer->_id,       // printerId
            RecoveryStage::COUNT_LINES // stage
        );

        while (
            (
                $line = stream_get_line(
                    stream: $gcode,
                    length: PrintGcode::STREAM_MAX_LINE_LENGTH_BYTES,
                    ending: PHP_EOL
                )
            ) !== false
        ) {
            $line = getGCode( $line );

            if (!$line) continue;

            /*
             * If a color swap is detected, we're gonna do the conversion in
             * memory, just in order to count the lines that would've been
             * added and thus, making sure that the line number is matched
             * properly.
             */
            if ($line->startsWith('M600')) {
                $lineNumberCount += count(
                    convertColorSwapToSequence(
                        command:          $line,
                        lastMovementMode: $lastMovementMode
                    )
                );
            } else {
                $lineNumberCount++;
            }

            if ($lineNumberCount < $this->printer->lastLine) {
                $previousPosition = $absolutePosition;

                if ($line == 'G90' || $line == 'G91') {
                    $lastMovementMode = (string) $line;
                } else if (
                    ($line->startsWith('G0') || $line->startsWith('G1'))
                    &&
                    !$line->endsWith(';' . FormatterCommands::IGNORE_POSITION_CHANGE)
                ) {
                    if ($lastMovementMode == 'G90') { // absolute mode
                        foreach (movementToXYZE( $line ) as $key => $value) {
                            $absolutePosition[ $key ] = $value;
                        }
                    } else if ($lastMovementMode == 'G91') { // relative mode
                        foreach (movementToXYZE( $line ) as $key => $value) {
                            if ($absolutePosition[ $key ] === null) {
                                $absolutePosition[ $key ]  = $value;
                            } else {
                                $absolutePosition[ $key ] += $value;
                            }
                        }
                    }
                } else if (
                    $line->startsWith('M104')  // set hotend temperature
                    ||
                    $line->startsWith('M140')  // set bed temperature
                    ||
                    $line->startsWith('M109')  // wait for hotend temperature
                    ||
                    $line->startsWith('M190')  // wait for bed temperature
                ) {
                    $warmUpCommands[] = (string) $line;
                } else if (
                    $line->exactly('M82')   // (E) extruder absolute mode
                    ||
                    $line->exactly('M83')   // (E) extruder relative mode
                    ||
                    $line->exactly('G21')   // use millimeters to measure distances
                ) {
                    $setUpCommands[] = (string) $line;
                }

                if ($absolutePosition['x'] !== null && $absolutePosition['y'] !== null) {
                    if ($minLayerPositionXY['x'] === null || $absolutePosition['x'] < $minLayerPositionXY['x']) {
                        $minLayerPositionXY['x'] = $absolutePosition['x'];
                    }

                    if ($minLayerPositionXY['y'] === null || $absolutePosition['y'] < $minLayerPositionXY['y']) {
                        $minLayerPositionXY['y'] = $absolutePosition['y'];
                    }
                }

                // Reset on layer change
                if ($absolutePosition['z'] != $previousPosition['z']) {
                    $minLayerPositionXY = [ 'x' => null, 'y' => null ];
                }

                Log::info('absolutePosition: ' . json_encode($absolutePosition));
            } else {
                foreach (array_keys($absolutePosition) as $key) {
                    if ($absolutePosition[$key] === null) {
                        $log->warning("{$this->printer->node}: {$this->printer->activeFile}: failed to assert absolute position, forcibly aborting job recovery. | X = {$absolutePosition['x']} - Y = {$absolutePosition['y']} - Z = {$absolutePosition['z']} - E = {$absolutePosition['e']}");

                        $this->dispatchBrowserEvent('recoveryJobFailedNoPosition');

                        $this->skip();

                        fclose( $targetFile );      // close stream

                        unlink( $targetFilePath );  // delete the file

                        return;
                    }
                }
            }
        }

        $preSetUpCommands[] = 'G90';                                        // absolute mode
        $preSetUpCommands[] = 'G92 X0 Y0 Z0 E0';                            // set all axis to 0
        $preSetUpCommands[] = 'G1 E-' . self::E_AXIS_RETRACT_OFFSET;        // retract N mm
        $preSetUpCommands[] = 'G0 Z'  . self::Z_AXIS_HOLDING_OFFSET;        // make the nozzle go up
        $preSetUpCommands[] = "M109 R{$jobRestorationHomingTemperature}";   // wait for hotend cooldown

        /*
         * This is gonna be the default target position, unless a minimum
         * position is known.
         */
        $startPos = [
            'x' => $absolutePosition['x'] + self::X_AXIS_HOLDING_OFFSET,
            'y' => $absolutePosition['y'] + self::Y_AXIS_HOLDING_OFFSET,
            'z' => $absolutePosition['z'] + self::Z_AXIS_HOLDING_OFFSET
        ];

        $log->debug('$startPos: defaults prepared: ' . json_encode( $startPos ));

        /*
         * If a minimum position is known, we're gonna try to go from $startPos
         * and offset that by XY_AXIS_WITH_MIN_HOLDING_OFFSET on both X and Y
         * axis.
         */
        if (
            $minLayerPositionXY['x'] !== null && $minLayerPositionXY['y'] !== null
            &&
            $startPos['x'] + self::XY_AXIS_WITH_MIN_HOLDING_OFFSET > $minLayerPositionXY['x']
            &&
            $startPos['y'] - self::XY_AXIS_WITH_MIN_HOLDING_OFFSET > $minLayerPositionXY['y']
        ) {
            $startPos['x'] += self::XY_AXIS_WITH_MIN_HOLDING_OFFSET;
            $startPos['y'] -= self::XY_AXIS_WITH_MIN_HOLDING_OFFSET;

            $log->debug('$startPos: minimum layer XY available, decreasing XY to a safer resting position: ' . json_encode( $startPos ) . ', minimum is: ' . json_encode( $minLayerPositionXY ));
        }

        $setUpCommands[] = 'G28';                                    // auto-home all axis
        $setUpCommands[] = "G0 Z{$startPos['z']}";                   // move to target Z   + offset
        $setUpCommands[] = "G0 X{$startPos['x']} Y{$startPos['y']}"; // move to target X/Y + offset
        $setUpCommands[] = "G0 Z{$absolutePosition['z']}";           // move to target Z

        $postSetUpCommands[] = "G0 X{$absolutePosition['x']} Y{$absolutePosition['y']}"; // move to target X/Y
        $postSetUpCommands[] = 'G1 E' . self::E_AXIS_RETRACT_OFFSET;                     // de-retract N mm
        $postSetUpCommands[] = $lastMovementMode;

        $log->debug('warmUpCommands: '    . json_encode( $warmUpCommands    ));
        $log->debug('preSetUpCommands: '  . json_encode( $preSetUpCommands  ));
        $log->debug('setUpCommands: '     . json_encode( $setUpCommands     ));
        $log->debug('postSetUpCommands: ' . json_encode( $postSetUpCommands ));

        fwrite(
            stream: $targetFile,
            data: implode(
                separator: PHP_EOL,
                array: array_merge(
                    $warmUpCommands,
                    $preSetUpCommands,
                    $setUpCommands,
                    $warmUpCommands,
                    $postSetUpCommands,
                    [ '; End of recovery sequence' ]
                )
            ) . PHP_EOL
        );

        rewind( $gcode );

        RecoveryStageChanged::dispatch(
            $this->printer->_id,      // printerId
            RecoveryStage::PARSE_FILE // stage
        );

        $progressPercentage = 0;

        while (
            (
                $line = stream_get_line(
                    stream: $gcode,
                    length: PrintGcode::STREAM_MAX_LINE_LENGTH_BYTES,
                    ending: PHP_EOL
                )
            ) !== false
        ) {
            $line = getGCode( $line );

            if (!$line) continue;

            /*
             * If a color swap is detected, we're gonna do the conversion in
             * memory, just in order to count the lines that would've been
             * added and thus, making sure that the line number is matched
             * properly.
             */
            if ($line->startsWith('M600')) {
                $lineNumber += count(
                    convertColorSwapToSequence(
                        command:          $line,
                        lastMovementMode: $lastMovementMode
                    )
                );
            } else {
                $lineNumber++;
            }

            if ($lineNumber >= $this->printer->lastLine) {
                fwrite(
                    stream: $targetFile,
                    data:   $line . PHP_EOL
                );
            }

            $newProgressPercentage = ($lineNumber * 100) / $lineNumberCount;

            if ($newProgressPercentage > 1) {
                $newProgressPercentage = ceil( $newProgressPercentage );
            } else if ($newProgressPercentage > 100) {
                $newProgressPercentage = 100;
            } else {
                $newProgressPercentage = round($newProgressPercentage);
            }

            if ($progressPercentage != $newProgressPercentage) {
                $progressPercentage = $newProgressPercentage;

                RecoveryProgress::dispatch(
                    $this->printer->_id, // printerId
                    $progressPercentage  // stage
                );
            }
        }

        fclose( $targetFile );

        $this->emit('refreshUploadedFiles');

        $this->printer->activeFile       = $newFileName;
        $this->printer->hasActiveJob     = true;
        $this->printer->lastJobHasFailed = false;
        $this->printer->save();

        PrintGcode::dispatch( $this->printer->activeFile );

        RecoveryCompleted::dispatch( $this->printer->_id );
    }

    public function mount() {
        $this->jobBackupInterval    = Configuration::get('jobBackupInterval');
        $this->jobBackupIntervals   = BackupInterval::asArray();
        $this->jobRecoveryStages    = RecoveryStage::asArray();
    }

    public function render()
    {
        return view('livewire.job-recovery-modal');
    }

}
