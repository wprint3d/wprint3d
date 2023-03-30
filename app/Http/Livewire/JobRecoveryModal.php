<?php

namespace App\Http\Livewire;

use App\Enums\BackupInterval;
use App\Enums\FormatterCommands;

use App\Jobs\PrintGcode;

use App\Libraries\Serial;

use App\Models\Configuration;
use App\Models\Printer;

use Illuminate\Support\Facades\Auth;
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

        $jobRestorationTimeoutSecs       = Configuration::get('jobRestorationTimeoutSecs',       env('JOB_BACKUP_RESTORE_TIMEOUT_SECS'));
        $jobRestorationHomingTemperature = Configuration::get('jobRestorationHomingTemperature', env('JOB_BACKUP_RESTORE_HOMING_TEMPERATURE'));

        $restoreStartTime = time();

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
            $log->debug('HIT WHILE');

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

        $warmUpCommands   = [];
        $preSetUpCommands = [];
        $setUpCommands    = [];

        // default movement mode for Marlin is absolute
        $lastMovementMode = 'G90';

        $absolutePosition = [ 'x' => null, 'y' => null, 'z' => null ];

        $gcode = Storage::get( $this->baseFilesDir . '/' . $this->printer->activeFile );
        $gcode = explode(PHP_EOL, $gcode);

        foreach ($gcode as $index => $line) {
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

            if ($index == $this->printer->lastLine) { break; }
        }

        if ($absolutePosition['x'] === null || $absolutePosition['y'] === null || $absolutePosition['z'] === null) {
            $log->warning("{$this->printer->node}: {$this->printer->activeFile}: failed to assert absolute position, forcibly aborting job recovery. | X = {$absolutePosition['x']} - Y = {$absolutePosition['y']} - Z = {$absolutePosition['z']}");

            $this->dispatchBrowserEvent('recoveryJobFailedNoPosition');

            $this->skip();

            return;
        }

        $preSetUpCommands[] = 'G91';    // relative mode
        $preSetUpCommands[] = 'G0 Z1';  // make the nozzle go up
        $preSetUpCommands[] = "M109 R{$jobRestorationHomingTemperature}"; // wait for hotend cooldown

        $startZ = $absolutePosition['z'] + 1;

        $setUpCommands[] = 'G28'; // auto-home all axis
        $setUpCommands[] = 'G90'; // absolute mode
        $setUpCommands[] = "G0  Z{$startZ}";                                          // move to target Z + 1mm
        $setUpCommands[] = "G0  X{$absolutePosition['x']} Y{$absolutePosition['y']}"; // move to target X/Y
        $setUpCommands[] = "G0  Z{$absolutePosition['z']}";                           // move to target Z
        $setUpCommands[] = $lastMovementMode;

        $gcode = array_merge(
            $warmUpCommands,
            $preSetUpCommands,
            $setUpCommands,
            $warmUpCommands,
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
