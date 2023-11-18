<?php

namespace App\Http\Livewire;

use App\Enums\BackupInterval;
use App\Enums\FormatterCommands;
use App\Enums\RecoveryStage;

use App\Events\RecoveryCompleted;
use App\Events\RecoveryProgress;
use App\Events\RecoveryStageChanged;
use App\Events\SystemMessage;

use App\Jobs\PrintGcode;
use App\Jobs\SendLinesToClientPreview;

use App\Libraries\Serial;

use App\Models\Configuration;
use App\Models\Printer;
use App\Models\User;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

use Illuminate\Support\Str;

use Livewire\Component;

use Exception;
use ReflectionClass;

class JobRecoveryModal extends Component
{

    public ?User    $user    = null;
    public ?Printer $printer = null;

    public $jobBackupInterval;
    public $jobBackupIntervals;
    public $jobRecoveryStages;

    public $recoveryMainMaxLine = 0;
    public $recoveryAltMaxLine  = 1;

    public $targetRecoveryLine;

    public string $uidSideA;
    public string $uidSideB;

    protected $baseFilesDir;
    protected $listeners = [ 'renderRecoveryGcode' => 'renderGcode' ];

    const RECOVERED_FILE_PREFIX = 'rec';

    const SERIAL_COMMAND_TIMEOUT_SECS = 3;

    const E_AXIS_RETRACT_OFFSET   =  5;   // mm
    const X_AXIS_HOLDING_OFFSET   = -1;   // mm
    const Y_AXIS_HOLDING_OFFSET   = -1;   // mm
    const Z_AXIS_HOLDING_OFFSET   =  1.5; // mm

    const XY_AXIS_WITH_MIN_HOLDING_OFFSET = 1; // mm

    const Z_PROBE_HOMING_OFFSET = 4.5; // mm

    public function boot() {
        $className = (new ReflectionClass($this))->getShortName();

        $this->uidSideA = uniqid( $className . '_' );
        $this->uidSideB = uniqid( $className . '_' );

        $this->user = Auth::user();

        $this->baseFilesDir = env('BASE_FILES_DIR');

        $this->printer =
            Printer::select('available', 'activeFile', 'hasActiveJob', 'lastJobHasFailed', 'lastLine')
                   ->find( $this->user->getActivePrinter() );

        if ($this->printer && $this->printer->lastLine !== null) {
            if ($this->printer->lastLine > 0) {
                $this->recoveryMainMaxLine = $this->printer->lastLine - 1;
            }

            $this->recoveryAltMaxLine = $this->recoveryMainMaxLine + 1;

            $this->targetRecoveryLine = $this->recoveryMainMaxLine;
        }
    }

    public function renderGcode(int $mainMaxLine, int $altMaxLine) {
        if (!$this->printer) {
            Log::warning( __METHOD__ . ': unexpected code path reached, why are we allowed to call this function without having a selected printer first?' );

            $this->dispatchBrowserEvent('recoveryJobPrepareError', 'No printer selected.');

            $this->skip();

            return;
        }

        if (!$this->printer) {
            $this->dispatchBrowserEvent('recoveryJobPrepareError', 'The printer related to this job was unexpectedly deleted.');

            $this->skip();

            return;
        }

        if (!$this->printer->activeFile) {
            $this->dispatchBrowserEvent('recoveryJobPrepareError', 'The printer is in an unexpected state. Did you modify the database manually?');

            $this->skip();

            return;
        }

        $this->targetRecoveryLine = $mainMaxLine;

        SendLinesToClientPreview::dispatch(
            $this->uidSideA,        // previewUID
            $this->printer->_id,    // printerId
            $mainMaxLine,           // currentLine
            false                   // mapLayers
        );

        SendLinesToClientPreview::dispatch(
            $this->uidSideB,        // previewUID
            $this->printer->_id,    // printerId
            $altMaxLine,            // currentLine
            false                   // mapLayers
        );
    }

    public function skip() {
        $this->printer->activeFile       = null;
        $this->printer->hasActiveJob     = false;
        $this->printer->lastJobHasFailed = false;
        $this->printer->lastLine         = null;
        $this->printer->save();

        SystemMessage::send('refreshActiveFile');
        SystemMessage::send('recoveryAborted');
    }

    public function recover() {
        SystemMessage::send('recoveryStarted');

        $log = Log::channel('job-recovery');

        $cacheMapperBusyKey = config('cache.mapper_busy_key');

        $jobRestorationTimeoutSecs       = Configuration::get('jobRestorationTimeoutSecs');
        $jobRestorationHomingTemperature = Configuration::get('jobRestorationHomingTemperature');

        $streamMaxLengthBytes = Configuration::get('streamMaxLengthBytes');

        $restoreStartTime = time();

        if (
            Cache::get(
                key:     $cacheMapperBusyKey,
                default: false
            )
        ) {
            $this->dispatchBrowserEvent('recoveryFailed', 'we\'re still negotiating a connection with this printer, please try again in a few seconds.');

            SystemMessage::send('recoveryAborted');

            return;
        }

        try {
            if (!$this->printer->node) {
                $this->printer->refresh();
            }

            if (!$this->printer->node) {
                $this->dispatchBrowserEvent('recoveryFailed', 'we don\'t know about this printer\'s node, please try unplugging the USB cable and plugging it back in.');

                SystemMessage::send('recoveryAborted');

                return;
            }
        } catch (Exception $exception) {
            $log->error(
                __METHOD__ . ': ' . $exception->getMessage() . PHP_EOL .
                $exception->getTraceAsString()
            );

            $this->dispatchBrowserEvent('recoveryFailed', $exception->getMessage() . '.');

            SystemMessage::send('recoveryAborted');

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

            $this->dispatchBrowserEvent('recoveryFailed', $exception->getMessage() . '.');

            SystemMessage::send('recoveryAborted');

            return;
        }

        while ($this->printer->activeFile) {
            $this->printer->refresh();

            if (time() - $restoreStartTime > $jobRestorationTimeoutSecs) {
                $this->dispatchBrowserEvent('recoveryTimedOut');

                SystemMessage::send('recoveryAborted');

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

        $preWarmUpCommands  = [];
        $warmUpCommands     = [];
        $preSetUpCommands   = [];
        $setUpCommands      = [];
        $postSetUpCommands  = [];

        // default movement mode for Marlin is absolute
        $lastMovementMode   = 'G90';

        // last tool change (T0, T1, etc.)
        $lastToolChange     = null;

        // (optional) M82 (absolute) or M83 (relative)
        $lastExtruderMode   = null;

        $previousPosition = [
            'x' => null,
            'y' => null,
            'z' => null,
            'e' => null
        ];

        $absolutePosition = $previousPosition;

        $minLayerPositionXY = [ 'x' => null, 'y' => null ];

        $gcode = Storage::getDriver()->readStream( $this->printer->activeFile );

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
            Str::of( basename($this->printer->activeFile) )->replaceMatches('/' . self::RECOVERED_FILE_PREFIX . '_[0-9]*_/', ''); // 'rec_##########_cube' => 'cube'

        $targetFilePath = env('BASE_FILES_DIR') . '/' . $newFileName;

        $absolutePath = Storage::path( $targetFilePath );

        $targetFile = fopen(
            filename: $absolutePath,
            mode:     'w' // Create the file, then, open for r/w.
        );

        $lineNumber      = 0;
        $lineNumberCount = 0;

        RecoveryStageChanged::dispatch(
            $this->printer->_id,       // printerId
            RecoveryStage::COUNT_LINES // stage
        );

        while (
            $line = readStreamLine(
                stream:    $gcode,
                maxLength: $streamMaxLengthBytes
            )
        ) {
            $line = getGCode( $line );

            if (!$line) continue;

            /*
             * If a color swap is detected, we're gonna do the conversion in
             * memory, just in order to count the lines that would've been
             * added and thus, making sure that the line number is matched
             * properly.
             */
            if (str_starts_with($line, 'M600')) {
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
                    (str_starts_with($line, 'G0') || str_starts_with($line, 'G1'))
                    &&
                    !str_ends_with($line, (';' . FormatterCommands::IGNORE_POSITION_CHANGE))
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
                } else if (str_starts_with($line, 'T')) { // tool/extruder change
                    $lastToolChange = (string) $line;
                } else if (
                    str_starts_with($line, 'M104')  // set hotend temperature
                    ||
                    str_starts_with($line, 'M140')  // set bed temperature
                    ||
                    str_starts_with($line, 'M109')  // wait for hotend temperature
                    ||
                    str_starts_with($line, 'M190')  // wait for bed temperature
                ) {
                    $warmUpCommands[] = (string) $line;
                } else if (
                    $line == 'M82'   // (E) extruder absolute mode
                    ||
                    $line == 'M83'   // (E) extruder relative mode
                    ||
                    $line == 'G21'   // use millimeters to measure distances
                ) {
                    $lastExtruderMode = (string) $line;
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
            } else {
                foreach (array_keys($absolutePosition) as $key) {
                    if ($absolutePosition[$key] === null) {
                        $log->warning("{$this->printer->node}: {$this->printer->activeFile}: failed to assert absolute position, forcibly aborting job recovery. | X = {$absolutePosition['x']} - Y = {$absolutePosition['y']} - Z = {$absolutePosition['z']} - E = {$absolutePosition['e']}");

                        $this->dispatchBrowserEvent('recoveryJobPrepareError', 'Failed to assert absolute position (not enough context in G-code).');

                        $this->skip();

                        fclose( $targetFile );      // close stream

                        unlink( $absolutePath );    // delete the file

                        return;
                    }
                }
            }
        }

        Log::debug( __METHOD__ . ': absolutePosition: ' . json_encode($absolutePosition) );

        $preSetUpCommands[] = 'G90';                                        // absolute mode
        $preSetUpCommands[] = 'G92 X0 Y0 Z0 E0';                            // set all axis to 0
        $preSetUpCommands[] = 'G1 E-' . self::E_AXIS_RETRACT_OFFSET;        // retract N mm
        $preSetUpCommands[] = "M109 R{$jobRestorationHomingTemperature}";   // wait for hotend cooldown

        /*
         * This is gonna be the default target position, unless a minimum
         * position is known.
         */
        $startPos = [
            'x' => $absolutePosition['x'] + self::X_AXIS_HOLDING_OFFSET,
            'y' => $absolutePosition['y'] + self::Y_AXIS_HOLDING_OFFSET
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

        if ($lastToolChange) {
            $preWarmUpCommands[] = $lastToolChange;
        }

        $setUpCommands[] = 'G28 X Y R0';                               // auto-home X and Y (avoid Z-raise by specifying R0)
        $setUpCommands[] = "G0  X{$startPos['x']} Y{$startPos['y']}";  // move to target X/Y + offset (Z is still at virtual 0 here)

        if ($this->printer->supports('zProbe')) {
            $setUpCommands[] = 'G90';                                  // absolute mode
            $setUpCommands[] = 'G92 Z'  . self::Z_PROBE_HOMING_OFFSET; // make the printer think it's still at Z_PROBE_HOMING_OFFSET
            $setUpCommands[] = 'G0  Z-' . self::Z_PROBE_HOMING_OFFSET; // discard additional Z_PROBE_HOMING_OFFSET Z raise (for z-probe)
            $setUpCommands[] = 'G92 Z0';                               // make the printer think it's still at Z0
        }

        $postSetUpCommands[] = "G0  X{$absolutePosition['x']} Y{$absolutePosition['y']}"; // move to target X/Y
        $postSetUpCommands[] = "G92 Z{$absolutePosition['z']} E{$absolutePosition['e']}"; // set virtual Z height and E position to the original absolute
        $postSetUpCommands[] = 'G1  E' . self::E_AXIS_RETRACT_OFFSET;                     // de-retract N mm
        $postSetUpCommands[] = $lastMovementMode;

        if ($lastExtruderMode) {
            $postSetUpCommands[] = $lastExtruderMode;
        }

        $log->debug('preWarmUpCommands: ' . json_encode( $preWarmUpCommands ));
        $log->debug('warmUpCommands: '    . json_encode( $warmUpCommands    ));
        $log->debug('preSetUpCommands: '  . json_encode( $preSetUpCommands  ));
        $log->debug('setUpCommands: '     . json_encode( $setUpCommands     ));
        $log->debug('postSetUpCommands: ' . json_encode( $postSetUpCommands ));

        fwrite(
            stream: $targetFile,
            data: implode(
                separator: PHP_EOL,
                array: array_merge(
                    $preWarmUpCommands,
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
            $line = readStreamLine(
                stream:    $gcode,
                maxLength: $streamMaxLengthBytes
            )
        ) {
            $line = getGCode( $line );

            if (!$line) continue;

            /*
             * If a color swap is detected, we're gonna do the conversion in
             * memory, just in order to count the lines that would've been
             * added and thus, making sure that the line number is matched
             * properly.
             */
            if (str_starts_with($line, 'M600')) {
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

        SystemMessage::send('refreshUploadedFiles');
        SystemMessage::send('recoveryCompleted', $newFileName);

        $this->printer->activeFile       = $targetFilePath;
        $this->printer->hasActiveJob     = true;
        $this->printer->lastJobHasFailed = false;
        $this->printer->save();

        PrintGcode::dispatch(
            $this->printer->activeFile, // filePath
            Auth::user(),               // owner
            $this->printer->_id         // printerId
        );

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
