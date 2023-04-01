<?php

namespace App\Http\Livewire;

use App\Enums\BackupInterval;
use App\Enums\FormatterCommands;

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

        $jobRestorationTimeoutSecs       = Configuration::get('jobRestorationTimeoutSecs',       env('JOB_BACKUP_RESTORE_TIMEOUT_SECS'));
        $jobRestorationHomingTemperature = Configuration::get('jobRestorationHomingTemperature', env('JOB_BACKUP_RESTORE_HOMING_TEMPERATURE'));

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

        $previousPosition = [ 'x' => null, 'y' => null, 'z' => null ];
        $absolutePosition = $previousPosition;

        $minLayerPositionXY = [ 'x' => null, 'y' => null ];

        $gcode = Storage::get( $this->baseFilesDir . '/' . $this->printer->activeFile );
        $gcode = explode(PHP_EOL, $gcode);

        foreach ($gcode as $index => $line) {
            $previousPosition = $absolutePosition;

            if ($line == 'G90' || $line == 'G91') {
                $lastMovementMode = $line;
            } else if (
                (Str::startsWith($line, 'G0') || Str::startsWith($line, 'G1'))
                &&
                !Str::endsWith($line, ';' . FormatterCommands::IGNORE_POSITION_CHANGE)
            ) {
                if ($lastMovementMode == 'G90') { // absolute mode
                    foreach (movementToXYZ( $line ) as $key => $value) {
                        $absolutePosition[ $key ] = $value;
                    }
                } else if ($lastMovementMode == 'G91') { // relative mode
                    foreach (movementToXYZ( $line ) as $key => $value) {
                        if ($absolutePosition[ $key ] === null) {
                            $absolutePosition[ $key ]  = $value;
                        } else {
                            $absolutePosition[ $key ] += $value;
                        }
                    }
                }
            } else if (
                Str::startsWith($line, 'M104')  // set hotend temperature
                ||
                Str::startsWith($line, 'M140')  // set bed temperature
                ||
                Str::startsWith($line, 'M109')  // wait for hotend temperature
                ||
                Str::startsWith($line, 'M190')  // wait for bed temperature
            ) {
                $warmUpCommands[] = $line;
            } else if (
                $line == 'M82'                  // (E) extruder absolute mode
                ||
                $line == 'M83'                  // (E) extruder relative mode
                ||
                Str::startsWith($line, 'G21')   // use millimeters to measure distances
            ) {
                $setUpCommands[] = $line;
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

            if ($index == $this->printer->lastLine) { break; }
        }

        if ($absolutePosition['x'] === null || $absolutePosition['y'] === null || $absolutePosition['z'] === null) {
            $log->warning("{$this->printer->node}: {$this->printer->activeFile}: failed to assert absolute position, forcibly aborting job recovery. | X = {$absolutePosition['x']} - Y = {$absolutePosition['y']} - Z = {$absolutePosition['z']}");

            $this->dispatchBrowserEvent('recoveryJobFailedNoPosition');

            $this->skip();

            return;
        }

        $preSetUpCommands[] = 'G91';                                        // relative mode
        $preSetUpCommands[] = 'G1 E-' . self::E_AXIS_RETRACT_OFFSET;        // retract N mm
        $preSetUpCommands[] = 'G0 Z'  . self::Z_AXIS_HOLDING_OFFSET;        // make the nozzle go up
        $preSetUpCommands[] = "M109 R{$jobRestorationHomingTemperature}";   // wait for hotend cooldown

        /*
         * This is gonna be the default target position, unless a minimum
         * position is known.
         */
        $startPos = [
            'x' => $absolutePosition['x'] + self::X_AXIS_HOLDING_OFFSET,
            'y' => $absolutePosition['x'] + self::Y_AXIS_HOLDING_OFFSET,
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

        $setUpCommands[] = 'G28'; // auto-home all axis
        $setUpCommands[] = 'G90'; // absolute mode
        $setUpCommands[] = "G0 X{$startPos['x']} Y{$startPos['y']} Z{$startPos['z']}"; // move to target X/Y/Z + offset
        $setUpCommands[] = "G0 Z{$absolutePosition['z']}";                             // move to target Z

        $postSetUpCommands[] = "G0 X{$absolutePosition['x']} Y{$absolutePosition['y']}"; // move to target X/Y
        $postSetUpCommands[] = 'G1 E' . self::E_AXIS_RETRACT_OFFSET;                     // de-retract N mm
        $postSetUpCommands[] = $lastMovementMode;

        $log->debug('warmUpCommands: '    . json_encode( $warmUpCommands    ));
        $log->debug('preSetUpCommands: '  . json_encode( $preSetUpCommands  ));
        $log->debug('setUpCommands: '     . json_encode( $setUpCommands     ));
        $log->debug('postSetUpCommands: ' . json_encode( $postSetUpCommands ));

        $gcode = array_merge(
            $warmUpCommands,
            $preSetUpCommands,
            $setUpCommands,
            $warmUpCommands,
            $postSetUpCommands,
            array_splice(
                array:  $gcode,
                offset: $this->printer->lastLine
            )
        );

        $gcode = implode(PHP_EOL, $gcode);

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

        Storage::put(
            path:     env('BASE_FILES_DIR') . '/' . $newFileName,
            contents: $gcode
        );

        $this->emit('refreshUploadedFiles');

        $this->printer->activeFile       = $newFileName;
        $this->printer->hasActiveJob     = true;
        $this->printer->lastJobHasFailed = false;
        $this->printer->save();

        PrintGcode::dispatch(
            fileName: $this->printer->activeFile,
            gcode:    $gcode
        );

        $this->dispatchBrowserEvent('recoveryCompleted');
    }

    public function mount() {
        $this->jobBackupInterval    = Configuration::get('jobBackupInterval', BackupInterval::fromKey( env('JOB_BACKUP_INTERVAL') )->value);
        $this->jobBackupIntervals   = BackupInterval::asArray();
    }

    public function render()
    {
        return view('livewire.job-recovery-modal');
    }

}
