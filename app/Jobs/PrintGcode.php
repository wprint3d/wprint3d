<?php

namespace App\Jobs;

use App\Enums\BackupInterval;
use App\Enums\FormatterCommands;
use App\Enums\Marlin;
use App\Enums\PauseReason;

use App\Events\PrinterConnectionStatusUpdated;
use App\Events\PrintJobFailed;
use App\Events\PrintJobFinished;

use App\Exceptions\TimedOutException;

use App\Libraries\Serial;

use App\Models\Configuration;
use App\Models\Printer;
use App\Models\User;

use Illuminate\Bus\Queueable;

use Illuminate\Contracts\Queue\ShouldQueue;

use Illuminate\Foundation\Bus\Dispatchable;

use Illuminate\Log\Logger;

use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use Illuminate\Support\Str;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

use Throwable;

use Exception;

class PrintGcode implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Indicate if the job should be marked as failed on timeout.
     *
     * @var bool
     */
    public $failOnTimeout = false;

    private string  $uid;
    private string  $filePath;
    private User    $owner;
    private mixed   $gcode;
    private string  $lastMovementMode;
    private int     $runningTimeoutSecs;
    private int     $commandTimeoutSecs;
    private int     $minPollIntervalSecs;
    private int     $jobBackupInterval;
    private int     $streamMaxLengthBytes;

    private int $lineNumber = 0;
    private int $lineNumberCount;

    private Printer $printer;

    private bool $shouldRecord;

    protected int   $lastSnapshot;
    protected array $recordableCameras;

    const LOG_CHANNEL = 'gcode-printer';

    const PRINTER_REFRESH_INTERVAL_SECS = 5;

    const COLOR_SWAP_DEFAULT_X = 0; // mm
    const COLOR_SWAP_DEFAULT_Y = 0; // mm
    const COLOR_SWAP_DEFAULT_Z = 50; // mm

    const COLOR_SWAP_DEFAULT_RETRACTION_LENGTH  = 5;    // mm
    const COLOR_SWAP_DEFAULT_LOAD_LENGTH        = 70;   // mm
    const COLOR_SWAP_EXTRUDER_FEED_RATE         = 250;  // mm/min
    const COLOR_SWAP_MOVEMENT_FEED_RATE         = 500;  // mm/min

    const STREAM_BUFFER_SIZE_MIN_LINES   = 100;  // lines
    const STREAM_BUFFER_SIZE_MAX_LINES   = 1000; // lines
    const STREAM_BUFFER_CHUNK_SIZE_LINES = 250;  // lines
    const STREAM_BUFFER_INTERVAL_SECS    = 10;   // seconds

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(string $filePath, User $owner, string $printerId)
    {
        $this->queue = 'prints';

        $this->uid      = uniqid( more_entropy: true );
        $this->filePath = $filePath;
        $this->owner    = $owner;
        $this->printer  = Printer::find( $printerId );

        $this->runningTimeoutSecs   = Configuration::get('runningTimeoutSecs');
        $this->commandTimeoutSecs   = Configuration::get('commandTimeoutSecs');
        $this->minPollIntervalSecs  = Configuration::get('lastSeenPollIntervalSecs');
        $this->jobBackupInterval    = Configuration::get('jobBackupInterval');
        $this->streamMaxLengthBytes = Configuration::get('streamMaxLengthBytes');

        $this->printer->setCurrentLine( 0 );

        $this->shouldRecord      = $this->owner->settings['recording']['enabled'];
        $this->recordableCameras = [];
    }

    /**
     * Handle a job finished.
     *
     * @return void
     */
    public function finished(bool $resetPrinter = false)
    {
        $log = Log::channel( self::LOG_CHANNEL );

        $this->printer->setCurrentLine( 0 );

        Cache::put(env('CACHE_MAX_LINE_KEY'), null);

        if ($resetPrinter) {
            $serial = new Serial(
                fileName:  $this->printer->node,
                baudRate:  $this->printer->baudRate,
                printerId: $this->printer->_id,
                timeout:   $this->commandTimeoutSecs,
                terminalAutoAppend: false
            );

            // Send command sequence for board reset
            foreach ([
                'M108',     // break and continue (get out of M0/M1)
                'M77',      // stop print job timer
                'M73 P0',   // reset print progress
                'M486 C',   // cancel objects
                'M107',     // turn off fan
                'M140 S0',  // turn off heatbed
                'M104 S0',  // turn off temperature
                'M84 X Y E' // disable motors
            ] as $command) {
                try {
                    $serial->query( $command );
                } catch (Exception $exception) {
                    $log->warning(
                        __METHOD__ . ': failed to send command: ' . $exception->getMessage() . PHP_EOL .
                        $exception->getTraceAsString()
                    );
                }
            }

            $this->printer->lastLine     = null;
            $this->printer->activeFile   = null;
            $this->printer->hasActiveJob = false;
        }

        $this->printer->save();

        // Disable last command reporting.
        $this->printer->setLastCommand(null);

        // Reset the printer's paused state in case it was left paused.
        $this->printer->resume();

        // Report that the job has finished.
        PrintJobFinished::dispatch( $this->printer->_id );

        // Dispatch video rendering job (if recording was enabled).
        if ($this->shouldRecord) {
            foreach ($this->recordableCameras as $camera) {
                Log::info( 'RenderVideo: dispatch! - ' . $camera->_id );

                RenderVideo::dispatch(
                    $this->owner,               // owner
                    $camera->index,             // index
                    $camera->requiresLibCamera, // requiresLibCamera
                    $this->filePath,            // fileName
                    $this->uid,                 // jobUID
                    $this->printer->_id         // printerId
                );
            }
        }

        $this->delete();
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

        $this->printer->lastJobHasFailed = true;

        try {
            PrintJobFailed::dispatch( $this->printer->_id );
        } catch (Exception $exception) {
            $log->warning(
                'PrintJobFailed: dispatch error: ' . $exception->getMessage() . PHP_EOL .
                $exception->getTraceAsString()
            );
        }

        $this->finished(resetPrinter: false);
    }

    private function retrySerialConnection(Exception $previousException, Serial &$serial, Logger &$log): string {
        /*
         * NOTE:
         * 
         * On low-end devices, the CPU load could cause the false impression of
         * the printer being frozen or crashed (the serial connection went out
         * of sync), because of that, we'll try to fetch the statistics of the
         * default extruder before giving up. If said query succeeds, the print
         * will be automatically resumed.
         */

        $log->warning('Timed out, looks like we haven\'t received a newline after the output of the last command. Let\'s try to get the statistics before giving up... Message: ' . $previousException->getMessage());

        try {
            $log->info('Trying to re-establish serial connection...');
            
            $log->info('A timing issue caused the serial connection to hang temporarily, trying again though, showed that the printer is still alive. Continuing print...');

            return $serial->query('M105');
        } catch (TimedOutException $statisticsTimedOutException) {
            throw $previousException; // throw the previous exception instead of the current one
        }
    }

    private function bufferChunk(mixed $stream, array &$buffer) {
        if (count($buffer) <= self::STREAM_BUFFER_SIZE_MIN_LINES) {
            $readLineCount = 0;

            while (
                $readLineCount < self::STREAM_BUFFER_CHUNK_SIZE_LINES
                &&
                (
                    $line = readStreamLine(
                        stream:    $stream,
                        maxLength: $this->streamMaxLengthBytes
                    )
                )
            ) {
                $command = getGCode( $line );

                if (!$command) continue;

                if ($command->exactly('G90') || $command->exactly('G91')) {
                    $this->lastMovementMode = $command->toString();
                }

                if ($command->exactly('M600') || $command->startsWith('M600 ')) {
                    $appendedCommands = convertColorSwapToSequence(
                        command:          $command,
                        lastMovementMode: $this->lastMovementMode
                    );

                    $this->lineNumberCount += count($appendedCommands);

                    $buffer = array_merge($buffer, $appendedCommands);

                    unset($appendedCommands);

                    Cache::put(env('CACHE_MAX_LINE_KEY'), $this->lineNumberCount);

                    continue;
                }

                $buffer[] = (string) $command;

                $readLineCount++;

                if (count($buffer) >= self::STREAM_BUFFER_SIZE_MAX_LINES) { break; }
            }
        }
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $this->gcode = Storage::getDriver()->readStream( $this->filePath );

        $this->printer->activeFile = $this->filePath;
        $this->printer->save();

        $log = Log::channel( self::LOG_CHANNEL );

        $log->info( 'Job started: printing "' . $this->filePath . '"' );

        if (!$this->printer->node) {
            throw new Exception('This printer doesn\'t have a node assigned.');
        }

        $statisticsQueryIntervalSecs = Configuration::get('jobStatisticsQueryIntervalSecs');
        $autoSerialIntervalSecs      = Configuration::get('autoSerialIntervalSecs');

        $log->info("Waiting {$autoSerialIntervalSecs} seconds before starting the job for the serial queue to clean up...");

        sleep($autoSerialIntervalSecs);

        $this->lineNumber      = $this->printer->getCurrentLine();
        $this->lineNumberCount = 0;

        // count lines
        while (
            $line = readStreamLine(
                stream:    $this->gcode,
                maxLength: $this->streamMaxLengthBytes
            )
        ) { $this->lineNumberCount++; }

        // back to line 0
        rewind( $this->gcode );

        $statistics = $this->printer->getStatistics();

        $serial = new Serial(
            fileName:  $this->printer->node,
            baudRate:  $this->printer->baudRate,
            printerId: $this->printer->_id,
            timeout:   $this->commandTimeoutSecs,
            terminalAutoAppend: false
        );

        if ($this->shouldRecord) {
            foreach ($this->printer->getRecordableCameras() as $camera) {
                if ($camera->connected) {
                    $this->recordableCameras[] = $camera;
                }
            }

            $serial->setProperty('lastSnapshot', millis());
            $serial->everyBusyMillis(
                clockName:  'lastSnapshot',
                interval:   $this->owner->settings['recording']['captureInterval'] * 1000,
                function:   function () {
                    foreach ($this->recordableCameras as $camera) {
                        $snapshotURL = $camera->getSnapshotURL();

                        if ($snapshotURL) {
                            SaveSnapshot::dispatch(
                                $camera->index,             // index
                                $camera->requiresLibCamera, // requiresLibCamera
                                $snapshotURL,               // url
                                $this->filePath,            // fileName
                                $this->uid                  // jobUID
                            );
                        }
                    }
                }
            );
        }

        $buffer = [
            'M75' // start print job timer
        ];

        $this->lineNumberCount++;

        Cache::put(env('CACHE_MAX_LINE_KEY'), $this->lineNumberCount);

        // default movement mode for Marlin is absolute
        $this->lastMovementMode = 'G90';

        $this->bufferChunk(
            stream: $this->gcode,
            buffer: $buffer
        );

        $lastStatsUpdate    = time();
        $lastPrinterRefresh = time();

        if ($this->jobBackupInterval != BackupInterval::NEVER) {
            $lastBackup = time();

            $this->printer->lastLine = $this->lineNumber;
            $this->printer->save();
        }

        $wasPaused = false;

        $absolutePosition = movementToXYZE(
            $serial->query('M114') // current absolute position
        );

        $this->printer->setAbsolutePosition(
            x:  $absolutePosition['x'] ?? null,
            y:  $absolutePosition['y'] ?? null,
            z:  $absolutePosition['z'] ?? null,
            e:  $absolutePosition['e'] ?? null
        );

        $progressPercentage = 0;

        tryToWaitForMapper($log);

        $lastSeen = $this->printer->getLastSeen();

        $lastCommandUpdate  = time();
        $lastPositionUpdate = time();

        while ($buffer) {
            $index = array_key_first($buffer);

            $time = time();

            $line = $buffer[ $index ];

            $absolutePosition = $this->printer->getAbsolutePosition();

            if ($line == ';' . FormatterCommands::GO_BACK) {
                $line = "G0 X{$absolutePosition['x']} Y{$absolutePosition['y']} Z{$absolutePosition['z']} F" . self::COLOR_SWAP_MOVEMENT_FEED_RATE;
            }

            if ($line == ';' . FormatterCommands::RESTORE_EXTRUDER) {
                $line = "G92 E{$absolutePosition['e']}";
            }

            if (!$this->printer->isRunning()) {
                $log->debug('PAUSE');

                $wasPaused = true;
            }

            while (!$this->printer->isRunning()) {
                if ($this->printer->getPauseReason() == PauseReason::AUTOMATIC) {
                    $received = $serial->query(
                        command:    'M105',
                        lineNumber: $this->lineNumber,
                        maxLine:    $this->lineNumberCount
                    );

                    if (Str::contains($received, 'ok')) {
                        $log->info('Resuming print...');

                        $this->printer->resume();

                        break;
                    }

                    $this->printer->refresh();

                    sleep(1);
                }

                if (!$this->printer->activeFile) break;
            }

            if (!$this->printer->activeFile) break;

            if ($wasPaused) {
                $log->debug('RESUME');

                $serial->query( 'M108' ); // break pause and continue unconditionally

                $wasPaused = false;

                $this->lineNumber = $this->printer->incrementCurrentLine();

                continue;
            }

            if ($time - $lastPrinterRefresh > self::PRINTER_REFRESH_INTERVAL_SECS) {
                $lastPrinterRefresh = time();

                $this->printer->refresh();

                if (!$this->printer->activeFile) {
                    $log->info('Job aborted.');

                    $this->finished( resetPrinter: true );

                    return;
                }
            }

            // Handle user pauses
            if ($line == 'M0' || $line == 'M1') {
                $this->printer->pause( PauseReason::AUTOMATIC );

                $log->debug('PAUSE: ' . $line);
            }

            $log->debug('PENDING: ' . $line);

            $received = $serial->query(
                command:      $line,
                lineNumber:   $this->lineNumber,
                maxLine:      $this->lineNumberCount
            );

            $log->debug('PROG: ' . $this->lineNumber . ' / ' . $this->lineNumberCount);

            if ($time - $lastSeen > $this->minPollIntervalSecs - 1) {
                $lastSeen = $this->printer->updateLastSeen();
            }

            if ($time - $lastCommandUpdate >= 1) {
                $this->printer->setLastCommand( Marlin::getLabel($line) );

                $serial->tryToAppendNow(
                    lineNumber: $this->lineNumber,
                    maxLine:    $this->lineNumberCount
                );

                $lastCommandUpdate = time();
            }

            if (time() - $lastStatsUpdate > $statisticsQueryIntervalSecs) {
                $lastStatsUpdate = time();

                $statistics = $this->printer->getStatistics();

                if (isset( $statistics['extruders'] )) {
                    foreach (array_keys($statistics['extruders']) as $extruderIndex) {
                        try {
                            $log->debug('Trying to refresh statistics...');

                            $this->printer->setStatistics(
                                lines:          $serial->query(
                                    command:    'M105 T' . $extruderIndex,
                                    lineNumber: $this->lineNumber,
                                    maxLine:    $this->lineNumberCount
                                ),
                                extruderIndex:  $extruderIndex
                            );
                        } catch (TimedOutException $exception) {
                            $this->retrySerialConnection($exception, $serial, $log);
                        }
                    }

                    try {
                        PrinterConnectionStatusUpdated::dispatch( $this->printer->_id );
                    } catch (Exception $exception) {
                        $log->warning(
                            __METHOD__ . ': PrinterConnectionStatusUpdated: event dispatch failure: ' . $exception->getMessage() . PHP_EOL .
                            $exception->getTraceAsString()
                        );
                    }
                }
            }

            if ($line == 'G90' || $line == 'G91') {
                $this->lastMovementMode = $line;
            } else if (
                (str_starts_with($line, 'G0') || str_starts_with($line, 'G1'))
                &&
                !str_ends_with($line, ';' . FormatterCommands::IGNORE_POSITION_CHANGE)
            ) {
                if ($this->lastMovementMode == 'G90') { // absolute mode
                    foreach (movementToXYZE( $line ) as $key => $value) {
                        $absolutePosition[ $key ] = $value;
                    }
                } else if ($this->lastMovementMode == 'G91') { // relative mode
                    foreach (movementToXYZE( $line ) as $key => $value) {
                        $absolutePosition[ $key ] += $value;
                    }
                }

                $log->debug('POS: ' . json_encode($absolutePosition));

                if (
                    $this->lineNumber == $this->lineNumberCount
                    ||
                    time() - $lastPositionUpdate >= 1
                ) {
                    $this->printer->setAbsolutePosition(
                        x:  $absolutePosition['x'],
                        y:  $absolutePosition['y'],
                        z:  $absolutePosition['z'],
                        e:  $absolutePosition['e']
                    );
                }
            }

            $lastProgressPercentage = round(
                num:       ($this->lineNumber * 100) / $this->lineNumberCount,
                precision: 2
            );

            if ($lastProgressPercentage > 0) {
                $lastProgressPercentage = ceil($lastProgressPercentage);
            }

            if ($lastProgressPercentage > 100) {
                $lastProgressPercentage = 100;
            }

            if ($lastProgressPercentage != $progressPercentage) {
                $serial->query("M73 P{$lastProgressPercentage}");

                $progressPercentage = $lastProgressPercentage;
            }

            if (Str::contains( $received, 'ok' )) {
                $this->lineNumber = $this->printer->incrementCurrentLine();

                if (
                    $this->jobBackupInterval != BackupInterval::NEVER // if it's not disabled
                    &&
                    (
                        $this->lineNumber == $this->lineNumberCount // and it's the last line
                        ||
                        (
                            (
                                $this->jobBackupInterval == BackupInterval::EVERY_SECOND // or the backup interval is set up to every second
                                &&
                                time() - $lastBackup >= 1
                            )
                            ||
                            (
                                $this->jobBackupInterval == BackupInterval::EVERY_5_MINUTES // or the backup interval is set to 5 minutes
                                &&
                                time() - $lastBackup > 5 * 60                               // and 5 minutes have passed
                            )
                        )
                    )
                ) {
                    $this->printer->lastLine = $this->lineNumber;
                    $this->printer->save();

                    $lastBackup = time();
                }
            }

            unset($buffer[ $index ]);

            $this->bufferChunk(
                stream: $this->gcode,
                buffer: $buffer
            );
        }

        $log->info('Job finished.');

        $this->finished( resetPrinter: true );
    }
}
