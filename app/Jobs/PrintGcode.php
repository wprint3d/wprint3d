<?php

namespace App\Jobs;

use App\Enums\FormatterCommands;
use App\Enums\Marlin;
use App\Enums\PauseReason;

use App\Events\PrinterConnectionStatusUpdated;

use App\Exceptions\TimedOutException;

use App\Libraries\Serial;

use App\Models\Printer;

use Illuminate\Bus\Queueable;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

use Illuminate\Foundation\Bus\Dispatchable;

use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

use MongoDB\BSON\UTCDateTime;

use Throwable;

class PrintGcode implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string  $fileName;
    private array   $gcode;
    private int     $runningTimeoutSecs;
    private int     $commandTimeoutSecs;
    private int     $minPollIntervalSecs;

    private Printer $printer;

    const LOG_CHANNEL = 'gcode-printer';

    const PRINTER_REFRESH_INTERVAL_SECS = 5;

    const COLOR_SWAP_DEFAULT_X = 0; // mm
    const COLOR_SWAP_DEFAULT_Y = 0; // mm
    const COLOR_SWAP_DEFAULT_Z = 50; // mm

    const COLOR_SWAP_DEFAULT_RETRACTION_LENGTH  = 5;    // mm
    const COLOR_SWAP_DEFAULT_LOAD_LENGTH        = 70;   // mm
    const COLOR_SWAP_EXTRUDER_FEED_RATE         = 250;  // mm/min
    const COLOR_SWAP_MOVEMENT_FEED_RATE         = 500;  // mm/min

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(string $fileName, string $gcode)
    {
        $this->fileName = $fileName; 
        $this->gcode    = explode(PHP_EOL, $gcode);
        $this->printer  = Printer::find( Auth::user()->activePrinter );;
        $this->runningTimeoutSecs   = env('PRINTER_RUNNING_TIMEOUT_SECS');
        $this->commandTimeoutSecs   = env('PRINTER_COMMAND_TIMEOUT_SECS');
        $this->minPollIntervalSecs  = env('PRINTER_LAST_SEEN_POLL_INTERVAL_SECS');

        $this->printer->setCurrentLine( 0 );
    }

    /**
     * Handle a job finished.
     *
     * @return void
     */
    public function finished(bool $resetPrinter = false)
    {
        $this->printer->setCurrentLine( 0 );

        Cache::put(env('CACHE_MAX_LINE_KEY'), null);

        if ($resetPrinter) {
            $serial = new Serial(
                fileName:  $this->printer->node,
                baudRate:  $this->printer->baudRate,
                printerId: $this->printer->_id,
                timeout:   $this->commandTimeoutSecs
            );

            // Send command sequence for board reset
            foreach ([
                'M486 C',   // cancel objects
                'M107',     // turn off fan
                'M140 S0',  // turn off heatbed
                'M104 S0',  // turn off temperature'
                'M84 X Y E' // disable motors
            ] as $command) {
                $serial->sendCommand( $command );
            }
        }

        $this->printer->activeFile  = null;
        $this->printer->save();

        // Disable last command reporting.
        $this->printer->setLastCommand(null);

        // Reset the printer's paused state in case it was left paused.
        $this->printer->resume();

        // Report that the printer has just finished printing.
        $this->printer->justFinished();
    }

    /**
     * Handle a job failure.
     *
     * @param  Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception)
    {
        $log = Log::channel( self::LOG_CHANNEL );

        $log->critical(
            $exception->getMessage() . PHP_EOL .
            $exception->getTraceAsString()
        );

        $this->finished(resetPrinter: true);
    }

    private function tryLastSeenUpdate() : bool {
        if (
            time() - $this->printer->lastSeen->toDateTime()->getTimestamp()
            >=
            $this->minPollIntervalSecs
        ) {
            $this->printer->lastSeen = new UTCDateTime();
            $this->printer->save();

            PrinterConnectionStatusUpdated::dispatch( $this->printer->_id );

            return true;
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
    private function movementToXYZ(string $command) : array {
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

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $this->printer->activeFile = $this->fileName;
        $this->printer->save();

        $log = Log::channel( self::LOG_CHANNEL );

        $log->info( 'Job started: printing "' . $this->fileName . '"' );

        $statisticsQueryIntervalSecs = env('PRINTING_STATISTICS_QUERY_INTERVAL_SECS');

        $statistics = $this->printer->getStatistics();

        $parsedGcode = [];

        // default movement mode for Marlin is absolute
        $lastMovementMode = 'G90';

        foreach ($this->gcode as $index => $line) {
            unset($this->gcode[ $index ]); // mark as free for GC

            // strip comments and empty lines)
            if (Str::startsWith($line, ';') || !$line) continue;

            $command = 
                Str::of( $line )
                   ->replaceMatches('/;.*/', '')
                   ->trim();

            if ($command->exactly('G90') || $command->exactly('G91')) {
                $lastMovementMode = $command->toString();
            }

            if ($command->exactly('M600') || $command->startsWith('M600 ')) {
                // $extruders = [];

                $retractionDistance     = null;
                $loadLength             = null;
                $resumeTemperature      = null;
                $resumeRetractionLength = null;

                $changeLocation = [
                    'X' => self::COLOR_SWAP_DEFAULT_X,
                    'Y' => self::COLOR_SWAP_DEFAULT_Y,
                    'Z' => self::COLOR_SWAP_DEFAULT_Z
                ];

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
                    $retractionDistance = self::COLOR_SWAP_DEFAULT_RETRACTION_LENGTH;
                }

                if (!$loadLength) {
                    $loadLength = self::COLOR_SWAP_DEFAULT_LOAD_LENGTH;
                }

                // if (!$extruders) {
                //     $extruders = array_keys( $statistics['extruders'] );
                // }

                if (!$resumeRetractionLength) {
                    $resumeRetractionLength = self::COLOR_SWAP_DEFAULT_RETRACTION_LENGTH;
                }

                $parsedGcode[] = 'G91';                                                                             // set relative movement mode
                $parsedGcode[] = 'M300 S885 P150';                                                                  // for now, we're just gonna beep once instead of reconstructing the whole sequence
                $parsedGcode[] = "G0 E-{$retractionDistance}";                                                      // retract before moving to change location

                // move to change location
                $parsedGcode[] = 'G90';                                                                             // set absolute movement mode
                $parsedGcode[] = "G0 X-{$changeLocation['X']} Y-{$changeLocation['Y']} Z{$changeLocation['Z']} ;" . FormatterCommands::IGNORE_POSITION_CHANGE;

                $parsedGcode[] = 'M0';                                                                              // wait for filament change

                if ($resumeTemperature) {
                    $parsedGcode[] = "M109 S{$resumeTemperature}";                                                  // wait for temperature before resuming
                }

                $parsedGcode[] = 'G91';                                                                             // set relative movement mode
                $parsedGcode[] = "G0 E{$loadLength} F" . self::COLOR_SWAP_EXTRUDER_FEED_RATE;                       // load the new filament
                $parsedGcode[] = "G0 E-{$resumeRetractionLength}";                                                  // retract a little bit

                // get back on top of the printed object
                $parsedGcode[] = 'G90';                                                                             // set absolute movement mode
                $parsedGcode[] = ";" . FormatterCommands::GO_BACK;                                                  // move back to previous location

                if ($lastMovementMode) {
                    $parsedGcode[] = $lastMovementMode;                                                             // reset last movement mode (if defined)
                }

                continue;
            }

            // remove inline comments
            $parsedGcode[] = $command->toString();
        }

        $this->gcode = array_values( $parsedGcode );

        unset($parsedGcode);

        $log->debug(print_r($this->gcode, true));

        $lineNumber      = $this->printer->getCurrentLine();
        $lineNumberCount = count($this->gcode);

        Cache::put(env('CACHE_MAX_LINE_KEY'), $lineNumberCount);

        $serial = new Serial(
            fileName:  $this->printer->node,
            baudRate:  $this->printer->baudRate,
            printerId: $this->printer->_id,
            timeout:   $this->commandTimeoutSecs
        );

        $lastStatsUpdate    = time();
        $lastPrinterRefresh = time();

        $isBusy     = false;
        $wasPaused  = false;

        // default movement mode for Marlin is absolute
        $lastMovementMode = 'G90';

        $absolutePosition = $this->movementToXYZ(
            $serial->query('M114') // current absolute position
        );

        $this->printer->setAbsolutePosition(
            x:  $absolutePosition['x'],
            y:  $absolutePosition['y'],
            z:  $absolutePosition['z']
        );

        while (isset($this->gcode[ $lineNumber ])) {
            $line = $this->gcode[ $lineNumber ];

            $absolutePosition = $this->printer->getAbsolutePosition();

            if ($line == ';' . FormatterCommands::GO_BACK) {
                $line = "G0 X{$absolutePosition['x']} Y{$absolutePosition['y']} Z{$absolutePosition['z']} F" . self::COLOR_SWAP_MOVEMENT_FEED_RATE;
            }

            if (!$this->printer->isRunning()) {
                $log->debug('PAUSE');

                $wasPaused = true;
            }

            while (!$this->printer->isRunning()) {
                if ($this->printer->getPauseReason() == PauseReason::AUTOMATIC) {
                    $received = $serial->query(
                        command:    'M105',
                        lineNumber: $lineNumber,
                        maxLine:    $lineNumberCount
                    );

                    if (Str::contains($received, 'ok')) $this->printer->resume();
                }

                sleep(1);
            }

            if ($wasPaused) {
                $log->debug('RESUME');

                $serial->sendCommand( 'M108' ); // break pause and continue unconditionally

                $wasPaused = false;
            }

            if ($isBusy) {
                try {
                    $received = $serial->readUntilBlank(
                        timeout:    $this->runningTimeoutSecs,
                        lineNumber: $lineNumber,
                        maxLine:    $lineNumberCount
                    );
                } catch (TimedOutException $exception) {
                    $log->info('Timed out, looks like we\'re out of BSY, let\'s try to keep going! Message: ' . $exception->getMessage());

                    $received = $serial->query(
                        command:    'M105',
                        lineNumber: $lineNumber,
                        maxLine:    $lineNumberCount
                    );
                }

                if (Str::contains($received, Printer::MARLIN_TEMPERATURE_INDICATOR)) {
                    $this->printer->setStatistics($received, 0);

                    PrinterConnectionStatusUpdated::dispatch( $this->printer->_id );
                }

                if (!Str::contains($received, 'ok')) {
                    $log->info('BSY: ' . $received);

                    $this->tryLastSeenUpdate();

                    continue;
                }

                $log->debug('LEFT BSY');

                $isBusy = false;

                $lineNumber = $this->printer->incrementCurrentLine();

                continue;
            }

            if (time() - $lastPrinterRefresh > self::PRINTER_REFRESH_INTERVAL_SECS) {
                $lastPrinterRefresh = time();

                $this->printer->refresh();

                if (!$this->printer->activeFile) {
                    $this->finished(true);

                    break;
                }
            }

            // Handle user pauses
            if ($line == 'M0' || $line == 'M1') {
                $this->printer->pause( PauseReason::AUTOMATIC );

                $log->debug('PAUSE: ' . $line);
            }

            $log->debug('PENDING: ' . $line);

            $serial->sendCommand(
                command:    $line,
                lineNumber: $lineNumber,
                maxLine:    $lineNumberCount
            );

            $log->debug('SENT');

            try {
                $received = $serial->readUntilBlank(
                    lineNumber: $lineNumber,
                    maxLine:    $lineNumberCount
                );
            } catch (TimedOutException $exception) {
                /*
                 * NOTE:
                 * 
                 * On low-end devices, the CPU load could cause the false
                 * impression of the printer being frozen or crashed, (the
                 * serial connection went out of sync), because of that, we'll
                 * try to fetch the statistics of the default extruder before
                 * giving up. If said query suceeds, the print will be
                 * automatically resumed.
                 */

                $log->warning('Timed out, looks like we haven\'t received a newline after the output of the last command. Let\'s try to get the statistics before giving up... Message: ' . $exception->getMessage());

                try {
                    $log->info('Trying to re-establish serial connection...');

                    $received = $serial->query('M105');

                    $log->info('A timing issue caused the serial connection to hang temporarily, trying again though, showed that the printer is still alive. Continuing print...');
                } catch (TimedOutException $statisticsTimedOutException) {
                    throw $exception; // throw the previous exception instead of the current one
                }
            }

            $log->debug('RECV: ' . $received);
            $log->debug('PROG: ' . $lineNumber . ' / ' . $lineNumberCount);

            $this->tryLastSeenUpdate();

            $this->printer->setLastCommand( Marlin::getLabel($line) );

            if (Str::contains($received, 'busy')) {
                $isBusy = true;

                $log->debug('NOW BSY!');

                continue;
            }

            if (
                !$isBusy
                &&
                time() - $lastStatsUpdate > $statisticsQueryIntervalSecs
            ) {
                $lastStatsUpdate = time();

                $statistics = $this->printer->getStatistics();

                if (isset( $statistics['extruders'] )) {
                    foreach (array_keys($statistics['extruders']) as $extruderIndex) {
                        $this->printer->setStatistics(
                            lines:          $serial->query(
                                command:    'M105 T' . $extruderIndex,
                                lineNumber: $lineNumber,
                                maxLine:    $lineNumberCount
                            ),
                            extruderIndex:  $extruderIndex
                        );
                    }
                }
            }

            if ($line == 'G90' || $line == 'G91') {
                $lastMovementMode = $line;
            } else if (
                (Str::startsWith($line, 'G0') || Str::startsWith($line, 'G1'))
                &&
                !Str::endsWith($line, ';' . FormatterCommands::IGNORE_POSITION_CHANGE)
            ) {
                if ($lastMovementMode == 'G90') { // absolute mode
                    foreach ($this->movementToXYZ( $line ) as $key => $value) {
                        $absolutePosition[ $key ] = $value;
                    }
                } else if ($lastMovementMode == 'G91') { // relative mode
                    foreach ($this->movementToXYZ( $line ) as $key => $value) {
                        $absolutePosition[ $key ] += $value;
                    }
                }

                $log->debug('POS: ' . json_encode($absolutePosition));

                $this->printer->setAbsolutePosition(
                    x:  $absolutePosition['x'],
                    y:  $absolutePosition['y'],
                    z:  $absolutePosition['z']
                );
            }

            if (Str::contains( $received, 'ok' )) {
                $lineNumber = $this->printer->incrementCurrentLine();
            }
        }

        $log->info('Job finished.');

        $this->finished();

        sleep(10);
    }
}
