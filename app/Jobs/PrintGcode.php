<?php

namespace App\Jobs;

use App\Enums\BackupInterval;
use App\Enums\FormatterCommands;
use App\Enums\Marlin;
use App\Enums\PauseReason;
use App\Enums\ToastMessageType;
use App\Events\PrintJobFailed;
use App\Events\PrintJobFinished;
use App\Events\ToastMessage;
use App\Exceptions\InitializationException;
use App\Exceptions\PrintRecoveryRequiredException;
use App\Exceptions\TimedOutException;
use App\Gcode\AdaptiveEta;
use App\Gcode\DurationEstimate;
use App\Gcode\GcodeDurationEstimator;
use App\Libraries\Serial;
use App\Models\Configuration;
use App\Models\File;
use App\Models\Printer;
use App\Models\User;
use App\Plugins\PluginHookCompiler;
use App\Plugins\PluginHookDispatcher;
use App\Services\PrintConnectionReconciler;
use App\Services\PrintExecutionState;
use Bayfront\MimeTypes\MimeType;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Log\Logger;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

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

    private FilesystemAdapter $storage;

    private string $uid;

    private ?string $executionToken = null;

    private string $filePath;

    private User $owner;

    private mixed $gcode;

    private string $lastMovementMode;

    private int $commandTimeoutSecs;

    private int $minPollIntervalSecs;

    private int $jobBackupInterval;

    private int $captureIntervalSecs;

    private ?array $serialPluginHooks = null;

    private ?string $serialNode = null;

    private ?int $serialBaudRate = null;

    private int $streamMaxLengthBytes;

    private int $lineNumber = 0;

    private int $commandsToSkip = 0;

    private int $lineNumberCount;

    private int $layerCount;

    private Printer $printer;

    private bool $shouldRecord;

    protected int $lastSnapshot;

    protected array $recordableCameras;

    const LOG_CHANNEL = 'gcode-printer';

    const PRINTER_REFRESH_INTERVAL_SECS = 5;

    const TERMINAL_REFRESH_INTERVAL_SECS = 1; // TODO: make sure that it doesn't go over Reverb's buffer size limit

    const COLOR_SWAP_DEFAULT_X = 0; // mm

    const COLOR_SWAP_DEFAULT_Y = 0; // mm

    const COLOR_SWAP_DEFAULT_Z = 50; // mm

    const COLOR_SWAP_DEFAULT_RETRACTION_LENGTH = 5;    // mm

    const COLOR_SWAP_DEFAULT_LOAD_LENGTH = 70;   // mm

    const COLOR_SWAP_EXTRUDER_FEED_RATE = 250;  // mm/min

    const COLOR_SWAP_MOVEMENT_FEED_RATE = 500;  // mm/min

    const STREAM_BUFFER_SIZE_MIN_LINES = 100;  // lines

    const STREAM_BUFFER_SIZE_MAX_LINES = 1000; // lines

    const STREAM_BUFFER_CHUNK_SIZE_LINES = 250;  // lines

    const STREAM_BUFFER_INTERVAL_SECS = 10;   // seconds

    private const COOL_DOWN_COMMANDS = [
        'M104 S0', // turn off hotend
        'M140 S0', // turn off heatbed
    ];

    private const PRINTER_RESET_COMMANDS = [
        'M108',      // break and continue (get out of M0/M1)
        'M77',       // stop print job timer
        'M73 P0',    // reset print progress
        'M486 C',    // cancel objects
        'M107',      // turn off fan
        ...self::COOL_DOWN_COMMANDS,
        'M84 X Y E', // disable motors
        'M999',      // restart from STOP (emergency abort)
    ];

    // A print must not inherit G91/M83 left active by manual or terminal commands.
    private const PRINT_START_MODE_COMMANDS = [
        'G21', // millimeter units
        'G90', // absolute XYZ positioning
        'M82', // absolute extruder positioning
    ];

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        User $owner,
        string $printerId,
        ?string $uid = null,
        ?string $executionToken = null
    ) {
        $this->queue = 'prints';

        $this->uid = $uid ?? uniqid(more_entropy: true);
        $this->executionToken = $executionToken;
        $this->owner = $owner;
        $this->printer = Printer::find($printerId);

        if (! $this->printer) {
            throw new InitializationException('The selected printer doesn\'t exist.');
        }

        if (! $this->printer->activeFile) {
            throw new InitializationException('This printer doesn\'t have an active file.');
        }

        $this->filePath = $this->printer->activeFile;

        $this->commandTimeoutSecs = Configuration::get('commandTimeoutSecs');
        $this->minPollIntervalSecs = Configuration::get('lastSeenPollIntervalSecs');
        $this->jobBackupInterval = Configuration::get('jobBackupInterval');
        $this->captureIntervalSecs = $this->owner->settings['recording']['captureInterval'];
        $this->streamMaxLengthBytes = Configuration::get('streamMaxLengthBytes');

        $this->shouldRecord = $this->owner->settings['recording']['enabled'];
        $this->recordableCameras = [];
    }

    /**
     * Handle a job finished.
     *
     * @return void
     */
    public function finished(bool $resetPrinter = false, bool $coolDown = false)
    {
        $log = Log::channel(self::LOG_CHANNEL);

        if (! $this->executionIsCurrent()) {
            $log->warning("[{$this->printer->_id}] Ignoring completion from a stale print execution.");
            $this->delete();

            return;
        }

        $this->printer->setCurrentLine(0);
        $this->printer->setMaxLine(0);

        if ($resetPrinter || $coolDown) {
            $this->sendPrinterCommands(
                $resetPrinter ? self::PRINTER_RESET_COMMANDS : self::COOL_DOWN_COMMANDS,
                $log
            );
        }

        if ($resetPrinter) {
            /*
             * M999 is part of PRINTER_RESET_COMMANDS because Hellbot printers
             * can otherwise remain unable to warm up after receiving the reset
             * sequence in rapid succession.
             */

            $this->printer->lastLine = null;
            $this->printer->activeFile = null;
            $this->printer->hasActiveJob = false;
        }

        $this->printer->save();

        // Disable last command reporting.
        $this->printer->setLastCommand(null);
        $this->printer->setPrintTiming(null);

        // Reset the printer's paused state in case it was left paused.
        $this->printer->resume();

        // Report that the job has finished.
        PrintJobFinished::dispatch($this->printer->_id);
        app(PluginHookDispatcher::class)->dispatch('print.job.finished', [
            'printerId' => $this->printer->_id,
            'filePath' => $this->filePath,
            'jobUid' => $this->uid,
            'resetPrinter' => $resetPrinter,
        ]);

        // Dispatch video rendering job (if recording was enabled).
        if ($this->shouldRecord) {
            foreach ($this->recordableCameras as $camera) {
                Log::info('RenderVideo: dispatch! - '.$camera->_id);

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

        if ($this->executionToken !== null) {
            $this->executionState()->clear($this->printer, $this->executionToken);
        }

        $this->delete();
    }

    /**
     * Handle a job failure.
     *
     * @return void
     */
    public function failed(Throwable $exception)
    {
        $log = Log::channel(self::LOG_CHANNEL);

        if (! $this->executionIsCurrent()) {
            $log->warning("[{$this->printer->_id}] Ignoring failure from a stale print execution: {$exception->getMessage()}");

            return;
        }

        $log->critical(
            $exception->getMessage().PHP_EOL.
            $exception->getTraceAsString()
        );

        $this->printer->hasActiveJob = false;
        $this->printer->lastJobHasFailed = true;

        if ($this->executionToken !== null) {
            $checkpoint = $this->executionState()->checkpoint((string) $this->printer->_id);

            if (is_array($checkpoint) && isset($checkpoint['displayedLine'])) {
                $this->printer->lastLine = (int) $checkpoint['displayedLine'];
            }
        }

        $this->printer->save();

        try {
            PrintJobFailed::dispatch($this->printer->_id);
            app(PluginHookDispatcher::class)->dispatch('print.job.failed', [
                'printerId' => $this->printer->_id,
                'filePath' => $this->filePath,
                'jobUid' => $this->uid,
                'message' => $exception->getMessage(),
            ]);
        } catch (Exception $dispatchException) {
            $log->warning(
                'PrintJobFailed: dispatch error: '.$dispatchException->getMessage().PHP_EOL.
                $dispatchException->getTraceAsString()
            );
        }

        $maySendCommands = ! ($exception instanceof PrintRecoveryRequiredException)
            || $exception->identityValidated;

        $this->finished(resetPrinter: false, coolDown: $maySendCommands);
    }

    private function sendPrinterCommands(array $commands, Logger $log): void
    {
        $serial = null;

        try {
            $serial = new Serial(
                fileName: $this->printer->node,
                baudRate: $this->printer->baudRate,
                printerId: $this->printer->_id,
                timeout: $this->commandTimeoutSecs,
                terminalAutoAppend: false,
                pluginHooks: $this->getSerialPluginHooks()
            );

            if ($this->executionToken !== null) {
                $serial->everyBusyMillis(
                    clockName: 'printExecutionHeartbeat',
                    interval: PrintExecutionState::HEARTBEAT_INTERVAL_SECS * 1000,
                    function: fn () => $this->heartbeat()
                );
            }

            foreach ($commands as $command) {
                try {
                    $this->heartbeat();
                    $serial->query($command);
                } catch (Exception $exception) {
                    $log->warning(
                        __METHOD__.': failed to send command: '.$exception->getMessage().PHP_EOL.
                        $exception->getTraceAsString()
                    );
                }
            }
        } catch (Exception $exception) {
            $log->warning(
                __METHOD__.': failed to connect for printer shutdown: '.$exception->getMessage().PHP_EOL.
                $exception->getTraceAsString()
            );
        } finally {
            $serial?->close();
        }
    }

    private function updatePrintedFile(?DurationEstimate $durationEstimate = null): void
    {
        $printedFile = File::where('path', $this->filePath)->first();

        if (! $printedFile) {
            $printedFile = new File;
            $printedFile->path = $this->filePath;
            $printedFile->prints = 0;
            $printedFile->size = $this->storage->size($this->filePath);
            $printedFile->save();
        }

        $printedFile->prints++;
        if ($durationEstimate !== null) {
            $printedFile->estimatedPrintTime = $durationEstimate->seconds;
            $printedFile->estimatedPrintTimeOrigin = $durationEstimate->origin;
            $printedFile->simulatedPrintTime = $durationEstimate->simulatedSeconds;
            $printedFile->gcodeLineCount = $durationEstimate->lineCount;
            $printedFile->hasUnboundedWait = $durationEstimate->hasUnboundedWait;
            $printedFile->analyzedAt = now();
        }
        $printedFile->save();
    }

    private function bufferChunk(mixed $stream, array &$buffer)
    {
        if (count($buffer) <= self::STREAM_BUFFER_SIZE_MIN_LINES) {
            $readLineCount = 0;

            while (
                $readLineCount < self::STREAM_BUFFER_CHUNK_SIZE_LINES
                &&
                (
                    $line = readStreamLine(
                        stream: $stream,
                        maxLength: $this->streamMaxLengthBytes
                    )
                )
            ) {
                $command = getGCode($line);

                if (! $command) {
                    continue;
                }

                if ($command == 'G90' || $command == 'G91') {
                    $this->lastMovementMode = $command;
                }

                if ($command == 'M600' || str_starts_with($command, 'M600 ')) {
                    $appendedCommands = convertColorSwapToSequence(
                        command: $command,
                        lastMovementMode: $this->lastMovementMode
                    );

                    $this->lineNumberCount += max(0, count($appendedCommands) - 1);

                    foreach ($appendedCommands as $appendedCommand) {
                        $this->appendBufferedCommand($buffer, $appendedCommand);
                    }

                    unset($appendedCommands);

                    $this->printer->setMaxLine($this->lineNumberCount);

                    continue;
                }

                $this->appendBufferedCommand($buffer, (string) $command);

                $readLineCount++;

                if (count($buffer) >= self::STREAM_BUFFER_SIZE_MAX_LINES) {
                    break;
                }
            }
        }
    }

    private function appendBufferedCommand(array &$buffer, string $command): void
    {
        if ($this->commandsToSkip > 0) {
            $this->commandsToSkip--;

            return;
        }

        $buffer[] = $command;
    }

    private function initializePrinterMotionModes(Serial $serial): void
    {
        foreach (self::PRINT_START_MODE_COMMANDS as $command) {
            $serial->query($command);
        }

        $this->lastMovementMode = 'G90';
    }

    private function openSerial(Logger $log): Serial
    {
        $maxRetries = max(0, (int) Configuration::get('negotiationMaxRetries'));
        $lastException = null;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                tryToWaitForMapper(
                    $log,
                    function (): void {
                        $this->heartbeat();
                        $this->assertCurrentExecution();
                    }
                );

                $node = (string) $this->printer->node;
                $baudRate = (int) $this->printer->baudRate;
                $serial = $this->createSerialConnection($node, $baudRate);
                $this->serialNode = $node;
                $this->serialBaudRate = $baudRate;

                return $serial;
            } catch (Throwable $exception) {
                $lastException = $exception;
                $this->heartbeat();
            }
        }

        throw new PrintRecoveryRequiredException(
            'Failed to open the printer connection for renegotiation: '.
                ($lastException?->getMessage() ?? 'unknown error')
        );
    }

    protected function createSerialConnection(string $node, int $baudRate): Serial
    {
        return new Serial(
            fileName: $node,
            baudRate: $baudRate,
            printerId: $this->printer->_id,
            timeout: $this->commandTimeoutSecs,
            terminalAutoAppend: false,
            pluginHooks: $this->getSerialPluginHooks()
        );
    }

    private function configurePrintSerial(Serial $serial): void
    {
        if ($this->executionToken !== null) {
            $serial->everyBusyMillis(
                clockName: 'printExecutionHeartbeat',
                interval: PrintExecutionState::HEARTBEAT_INTERVAL_SECS * 1000,
                function: fn () => $this->heartbeat()
            );
        }

        if (! isset($this->shouldRecord) || ! $this->shouldRecord) {
            return;
        }

        $serial->everyBusyMillis(
            clockName: 'lastSnapshot',
            interval: $this->captureIntervalSecs * 1000,
            function: function () {
                foreach ($this->recordableCameras as $camera) {
                    $snapshotURL = $camera->getSnapshotURL();

                    if ($snapshotURL) {
                        SaveSnapshot::dispatch(
                            $camera->index,
                            $camera->requiresLibCamera,
                            $snapshotURL,
                            $this->filePath,
                            $this->uid,
                            $this->captureIntervalSecs
                        );
                    }
                }
            }
        );
    }

    private function executionState(): PrintExecutionState
    {
        return app(PrintExecutionState::class);
    }

    private function connectionReconciler(): PrintConnectionReconciler
    {
        return app(PrintConnectionReconciler::class);
    }

    private function executionIsCurrent(): bool
    {
        return $this->executionToken === null
            || $this->executionState()->isCurrent((string) $this->printer->_id, $this->executionToken);
    }

    private function assertCurrentExecution(): void
    {
        if (! $this->executionIsCurrent()) {
            throw new Exception('The print execution was superseded by a newer worker.');
        }
    }

    private function heartbeat(): void
    {
        if ($this->executionToken !== null) {
            $this->executionState()->heartbeat((string) $this->printer->_id, $this->executionToken);
        }
    }

    private function synchronizeCheckpoint(array $checkpoint): void
    {
        $this->lineNumber = (int) ($checkpoint['displayedLine'] ?? $this->lineNumber);
        $state = $checkpoint['state'] ?? [];
        $position = $state['position'] ?? [];

        $this->lastMovementMode = $state['modal']['movement'] ?? $this->lastMovementMode;
        $this->printer->setCurrentLine($this->lineNumber);
        $this->printer->setAbsolutePosition(
            x: isset($position['x']) ? (float) $position['x'] : null,
            y: isset($position['y']) ? (float) $position['y'] : null,
            z: isset($position['z']) ? (float) $position['z'] : null,
            e: isset($position['e']) ? (float) $position['e'] : null
        );
        $this->heartbeat();
    }

    private function returnPosition(array $fallback): array
    {
        if ($this->executionToken === null) {
            return $fallback;
        }

        $checkpoint = $this->executionState()->checkpoint((string) $this->printer->_id);
        $returnPosition = $checkpoint['state']['returnPosition'] ?? null;

        return is_array($returnPosition) ? $returnPosition : $fallback;
    }

    private function commitPendingCommand(?array $observedPosition = null): array
    {
        $checkpoint = $this->executionState()->commitPending(
            (string) $this->printer->_id,
            (string) $this->executionToken,
            $this->printer->getStatistics(),
            $observedPosition
        );
        $this->synchronizeCheckpoint($checkpoint);

        return $checkpoint;
    }

    private function waitForConnectionRetry(int $seconds): void
    {
        for ($elapsed = 0; $elapsed < $seconds; $elapsed++) {
            $this->heartbeat();
            $this->assertCurrentExecution();
            sleep(1);
        }
    }

    private function reconcileMappedConnection(Serial &$serial, array $checkpoint): string
    {
        $log = Log::channel(self::LOG_CHANNEL);
        $maxRetries = max(0, (int) Configuration::get('negotiationMaxRetries'));
        $retryDelaySecs = max(1, (int) Configuration::get('negotiationTimeoutSecs'));
        $executionContext = $this->printer->activePrintExecution ?? [];

        if (! is_array($executionContext)) {
            $executionContext = [];
        }

        $expectedFingerprint = (string) (
            $checkpoint['machineFingerprint']
            ?? $executionContext['machineFingerprint']
            ?? ''
        );
        $serialNode = $this->serialNode ?? (string) $this->printer->node;
        $serialBaudRate = $this->serialBaudRate ?? (int) $this->printer->baudRate;
        $lastException = null;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                tryToWaitForMapper(
                    $log,
                    function (): void {
                        $this->heartbeat();
                        $this->assertCurrentExecution();
                    }
                );

                $this->printer->refresh();
            } catch (Throwable $exception) {
                $lastException = $exception;

                if ($attempt < $maxRetries) {
                    $this->waitForConnectionRetry($retryDelaySecs);
                }

                continue;
            }

            $mappedFingerprint = (string) ($this->printer->machine['uuid'] ?? '');

            if (
                $expectedFingerprint === ''
                || $mappedFingerprint === ''
                || ! hash_equals($expectedFingerprint, $mappedFingerprint)
            ) {
                throw new PrintRecoveryRequiredException(
                    'The remapped serial device fingerprint does not match the active print.'
                );
            }

            $mappedNode = (string) $this->printer->node;
            $mappedBaudRate = (int) $this->printer->baudRate;

            try {
                if ($mappedNode === '') {
                    throw new InitializationException('The remapped printer has no serial node.');
                }

                if ($mappedNode !== $serialNode || $mappedBaudRate !== $serialBaudRate) {
                    $log->warning(
                        "[{$this->printer->_id}] Printer fingerprint matched after remapping; ".
                        "moving the active print connection from {$serialNode} to {$mappedNode}."
                    );

                    $serial->close();
                    $serial = $this->createSerialConnection($mappedNode, $mappedBaudRate);
                    $this->configurePrintSerial($serial);
                    $serialNode = $mappedNode;
                    $serialBaudRate = $mappedBaudRate;
                    $this->serialNode = $mappedNode;
                    $this->serialBaudRate = $mappedBaudRate;
                }

                return $this->connectionReconciler()->reconcile($this->printer, $serial, $checkpoint);
            } catch (PrintRecoveryRequiredException $exception) {
                if ($exception->identityValidated) {
                    throw $exception;
                }

                $lastException = $exception;
            } catch (Throwable $exception) {
                $lastException = $exception;
            }

            if ($attempt < $maxRetries) {
                $this->waitForConnectionRetry($retryDelaySecs);
            }
        }

        throw new PrintRecoveryRequiredException(
            'Failed to renegotiate the mapped printer connection after the configured attempts: '.
                ($lastException?->getMessage() ?? 'unknown error')
        );
    }

    private function reconcilePendingCommand(Serial &$serial): string
    {
        $checkpoint = $this->executionState()->checkpoint((string) $this->printer->_id);

        if (! is_array($checkpoint)) {
            throw new PrintRecoveryRequiredException('The active print checkpoint disappeared.');
        }

        $outcome = $this->reconcileMappedConnection($serial, $checkpoint);

        if ($outcome === 'continue') {
            $this->synchronizeCheckpoint($checkpoint);

            return 'ok';
        }

        if ($outcome === 'executed') {
            $this->commitPendingCommand();

            return 'ok';
        }

        if ($outcome !== 'resend' || ! is_array($checkpoint['pending'] ?? null)) {
            throw new PrintRecoveryRequiredException(
                'The pending command could not be reconciled.',
                identityValidated: true
            );
        }

        $pendingCommand = $checkpoint['pending']['command'];

        try {
            $response = $serial->query(
                command: $pendingCommand,
                lineNumber: $this->lineNumber,
                maxLine: $this->lineNumberCount
            );
        } catch (TimedOutException|InitializationException|LockTimeoutException) {
            $checkpoint = $this->executionState()->checkpoint((string) $this->printer->_id);
            $secondOutcome = $this->reconcileMappedConnection($serial, $checkpoint);

            if ($secondOutcome !== 'executed') {
                throw new PrintRecoveryRequiredException(
                    'The resent command still has an ambiguous physical outcome.',
                    identityValidated: true
                );
            }

            $response = 'ok';
        }

        if (! Str::contains($response, 'ok')) {
            throw new PrintRecoveryRequiredException(
                'The printer did not acknowledge the reconciled command.',
                identityValidated: true
            );
        }

        $this->commitPendingCommand();

        return $response;
    }

    private function queryPrintCommand(
        Serial &$serial,
        string $command,
        bool $sourceCommand = false,
        ?int $lineNumber = null,
        ?int $maxLine = null
    ): string {
        if ($this->executionToken === null) {
            return $serial->query($command, $lineNumber, $maxLine);
        }

        $this->assertCurrentExecution();
        $this->heartbeat();
        $pending = $this->executionState()->preparePending(
            (string) $this->printer->_id,
            $this->executionToken,
            $command,
            $sourceCommand
        );

        try {
            $response = $serial->query($command, $lineNumber, $maxLine);
        } catch (TimedOutException|InitializationException|LockTimeoutException) {
            return $this->reconcilePendingCommand($serial);
        }

        if (! Str::contains($response, 'ok')) {
            throw new PrintRecoveryRequiredException(
                "The printer did not acknowledge command '{$command}'.",
                identityValidated: true
            );
        }

        $observedPosition = null;

        if ($pending['requiresPositionRefresh'] ?? false) {
            $checkpoint = $this->executionState()->checkpoint((string) $this->printer->_id);
            $observedPosition = $this->connectionReconciler()
                ->observe($this->printer, $serial, $checkpoint)['position'];
        }

        $this->commitPendingCommand($observedPosition);

        return $response;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $log = Log::channel(self::LOG_CHANNEL);
        $this->printer->refresh();

        if (! $this->printer->hasActivePrintJob()) {
            if ($this->executionToken !== null && $this->executionIsCurrent()) {
                $this->executionState()->clear($this->printer, $this->executionToken);
            }

            $log->info("[{$this->printer->_id}] Discarding an inactive print execution.");
            $this->delete();

            return;
        }

        $this->assertCurrentExecution();
        $checkpoint = $this->executionToken !== null
            ? $this->executionState()->checkpoint((string) $this->printer->_id)
            : null;
        $isResuming = is_array($checkpoint)
            && ($checkpoint['ready'] ?? false) === true
            && ($checkpoint['uid'] ?? null) === $this->uid;

        $log->info(
            $isResuming
                ? "Job resumed: printing \"{$this->filePath}\""
                : "Job started: printing \"{$this->filePath}\""
        );

        if (! $isResuming) {
            app(PluginHookDispatcher::class)->dispatch('print.job.started', [
                'printerId' => $this->printer->_id,
                'filePath' => $this->filePath,
                'jobUid' => $this->uid,
                'ownerId' => $this->owner->_id,
            ]);
        }

        $this->heartbeat();

        $this->storage = Storage::disk('gcode');

        $fileMimeType = Storage::mimeType($this->filePath);

        if (
            $fileMimeType !== false
            &&
            $fileMimeType !== MimeType::fromExtension('txt')
        ) {
            ToastMessage::dispatch(
                $this->owner->_id,                                                                                  // userId
                ToastMessageType::ERROR,                                                                            // type
                "Couldn't start print: the selected file with type \"<b>{$fileMimeType}</b>\" is not compatible."   // message
            );

            $log->info("Aborted: incompatible file type \"{$fileMimeType}\".");

            $this->finished(resetPrinter: true);

            return;
        }

        $durationEstimate = null;
        $adaptiveEta = null;

        try {
            $log->debug(__METHOD__.': trying to estimate print time...');

            $durationEstimate = (new GcodeDurationEstimator)->estimate(
                Storage::disk('gcode')->path($this->filePath),
                function (): void {
                    $this->heartbeat();
                    $this->assertCurrentExecution();
                }
            );
            $expectedPrintTimeSecs = $durationEstimate->seconds;

            $log->info(__METHOD__.": this print should take about {$expectedPrintTimeSecs} seconds.");

            if ($expectedPrintTimeSecs > 0) {
                $adaptiveEta = new AdaptiveEta($expectedPrintTimeSecs);
                if (! $isResuming) {
                    $this->printer->setPrintTiming([
                        'estimatedSeconds' => $expectedPrintTimeSecs,
                        'printTime' => 0,
                        'printTimeLeft' => null,
                        'printTimeLeftOrigin' => $durationEstimate->origin,
                        'stable' => false,
                        'hasUnboundedWait' => $durationEstimate->hasUnboundedWait,
                    ]);
                }
            }
        } catch (Exception $exception) {
            $log->warning(
                __METHOD__.': couldn\'t query estimated print time: '.$exception->getMessage().PHP_EOL.
                $exception->getTraceAsString()
            );
        }

        $this->gcode = $this->storage->getDriver()->readStream($this->filePath);

        if (! $this->printer->node) {
            throw new Exception('This printer doesn\'t have a node assigned.');
        }

        $statisticsQueryIntervalSecs = Configuration::get('jobStatisticsQueryIntervalSecs');
        $autoSerialIntervalSecs = Configuration::get('autoSerialIntervalSecs');

        if (! $isResuming) {
            $log->info("Waiting {$autoSerialIntervalSecs} seconds before starting the job for the serial queue to clean up...");

            sleep($autoSerialIntervalSecs);
        }

        $this->heartbeat();

        $this->lineNumber = $this->printer->getCurrentLine();
        $this->lineNumberCount = 0;
        $this->layerCount = 0;

        $lastMovementMode = null;

        // count lines
        while (
            $line = readStreamLine(
                stream: $this->gcode,
                maxLength: $this->streamMaxLengthBytes
            )
        ) {
            $this->lineNumberCount++;

            if ($this->lineNumberCount % 1000 === 0) {
                $this->heartbeat();
                $this->assertCurrentExecution();
            }

            if (str_starts_with($line, 'G90') || str_starts_with($line, 'G91')) {
                $lastMovementMode = $line;
            }

            if (! str_starts_with($line, 'G0') && ! str_starts_with($line, 'G1')) {
                continue;
            }

            if (! str_contains($line, ' Z') && ! str_contains($line, "\tZ")) {
                continue;
            }

            $nextVirtualPosition = movementToXYZE($line);

            if (! isset($virtualPosition)) {
                $virtualPosition = ['z' => 0];
            }

            if ($lastMovementMode === null || $lastMovementMode == 'G90') {
                if (isset($nextVirtualPosition['z']) && $nextVirtualPosition['z'] != $virtualPosition['z']) {
                    $virtualPosition['z'] = $nextVirtualPosition['z'];

                    $this->layerCount++;
                }
            } else {
                $log->debug("Relative movement detected, skipping layer count check... {$line}");

                $nextVirtualPosition['z'] += $virtualPosition['z'];

                if ($nextVirtualPosition['z'] != $virtualPosition['z']) {
                    $this->layerCount++;
                }
            }
        }

        $this->printer->setMaxLayer($this->layerCount);

        // back to line 0
        rewind($this->gcode);

        $serial = $this->openSerial($log);

        try {
            if ($this->shouldRecord) {
                foreach ($this->printer->getRecordableCameras() as $camera) {
                    if ($camera->connected) {
                        $this->recordableCameras[] = $camera;
                    }
                }
            }

            $this->configurePrintSerial($serial);

            $buffer = [];
            $this->lineNumberCount++;
            $this->printer->setMaxLine($this->lineNumberCount);
            $this->lastMovementMode = 'G90';

            if ($isResuming) {
                $this->reconcilePendingCommand($serial);
                $checkpoint = $this->executionState()->checkpoint((string) $this->printer->_id);
                $this->commandsToSkip = (int) ($checkpoint['sourceCommandIndex'] ?? 0);
                $this->synchronizeCheckpoint($checkpoint);
            } else {
                try {
                    $this->initializePrinterMotionModes($serial);

                    $detectedAbsolutePosition = movementToXYZE(
                        $serial->query('M114')
                    );
                    $serial->query('M105');
                } catch (TimedOutException|InitializationException|LockTimeoutException $exception) {
                    throw new PrintRecoveryRequiredException(
                        'Failed to establish the initial physical print checkpoint: '.$exception->getMessage()
                    );
                }

                if ($this->executionToken !== null) {
                    $checkpoint = $this->executionState()->markReady(
                        (string) $this->printer->_id,
                        $this->executionToken,
                        [
                            'x' => $detectedAbsolutePosition['x'] ?? null,
                            'y' => $detectedAbsolutePosition['y'] ?? null,
                            'z' => $detectedAbsolutePosition['z'] ?? null,
                            'e' => $detectedAbsolutePosition['e'] ?? null,
                        ],
                        $this->printer->getStatistics(),
                        displayedLine: 1
                    );
                    $this->synchronizeCheckpoint($checkpoint);
                    $this->reconcilePendingCommand($serial);
                    $this->queryPrintCommand($serial, 'M75');
                } else {
                    $serial->query('M75');
                    $this->lineNumber = $this->printer->incrementCurrentLine();
                    $this->printer->setAbsolutePosition(
                        x: $detectedAbsolutePosition['x'] ?? null,
                        y: $detectedAbsolutePosition['y'] ?? null,
                        z: $detectedAbsolutePosition['z'] ?? null,
                        e: $detectedAbsolutePosition['e'] ?? null
                    );
                }

                $this->updatePrintedFile($durationEstimate);
            }

            $checkpoint = $this->executionToken !== null
                ? $this->executionState()->checkpoint((string) $this->printer->_id)
                : null;
            $absolutePosition = $checkpoint['state']['position'] ?? $this->printer->getAbsolutePosition();

            $this->bufferChunk(
                stream: $this->gcode,
                buffer: $buffer
            );

            $lastStatsUpdate = time();
            $lastPrinterRefresh = time();

            if ($this->jobBackupInterval != BackupInterval::NEVER) {
                $lastBackup = time();

                $this->printer->lastLine = $this->lineNumber;
                $this->printer->save();
            }

            $wasPaused = false;
            $progressPercentage = min(100, max(0, (int) ceil(
                ($this->lineNumber * 100) / max(1, $this->lineNumberCount)
            )));

            $lastSeen = $this->printer->getLastSeen();

            $lastCommandUpdate = time();

            while ($buffer) {
                $index = array_key_first($buffer);

                $time = time();

                $line = $buffer[$index];

                if ($line == ';'.FormatterCommands::GO_BACK) {
                    $returnPosition = $this->returnPosition($absolutePosition);
                    $line = "G0 X{$returnPosition['x']} Y{$returnPosition['y']} Z{$returnPosition['z']} F".self::COLOR_SWAP_MOVEMENT_FEED_RATE;
                }

                if ($line == ';'.FormatterCommands::RESTORE_EXTRUDER) {
                    $returnPosition = $this->returnPosition($absolutePosition);
                    $line = "G92 E{$returnPosition['e']}";
                }

                if (! $this->printer->isRunning()) {
                    $log->debug('PAUSE');

                    $wasPaused = true;
                }

                while (! $this->printer->isRunning()) {
                    $this->heartbeat();
                    $this->assertCurrentExecution();

                    if ($adaptiveEta) {
                        $timing = $adaptiveEta->sample($this->lineNumber, $this->lineNumberCount, false);
                        $timing['hasUnboundedWait'] = $durationEstimate?->hasUnboundedWait ?? false;
                        $this->printer->setPrintTiming($timing);
                    }
                    if ($this->printer->getPauseReason() == PauseReason::AUTOMATIC) {
                        $received = $this->queryPrintCommand(
                            serial: $serial,
                            command: 'M105',
                            sourceCommand: false,
                            lineNumber: $this->lineNumber,
                            maxLine: $this->lineNumberCount
                        );

                        if (Str::contains($received, 'ok')) {
                            $log->info('Resuming print...');

                            $this->printer->resume();

                            break;
                        }

                        $this->printer->refresh();

                        sleep(1);
                    } else {
                        $this->printer->refresh();
                        time_nanosleep(seconds: 0, nanoseconds: 100 * 1000 * 1000);
                    }

                    if (! $this->printer->activeFile) {
                        break;
                    }
                }

                if (! $this->printer->activeFile) {
                    break;
                }

                if ($wasPaused) {
                    $log->debug('RESUME');

                    $this->queryPrintCommand($serial, 'M108'); // break pause and continue unconditionally

                    $wasPaused = false;

                    $adaptiveEta?->sample($this->lineNumber, $this->lineNumberCount, true);
                }

                if ($time - $lastPrinterRefresh > self::PRINTER_REFRESH_INTERVAL_SECS) {
                    $lastPrinterRefresh = time();

                    $this->printer->refresh();

                    if (! $this->printer->activeFile) {
                        $serial->tryToAppendNow();
                        $serial->close();

                        $log->info('Job aborted.');

                        $this->finished(resetPrinter: true);

                        return;
                    }
                }

                // Handle user pauses
                if ($line == 'M0' || $line == 'M1') {
                    $this->printer->pause(PauseReason::AUTOMATIC);

                    $log->debug('PAUSE: '.$line);
                }

                $log->debug('PENDING: '.$line);

                $previousPosition = $absolutePosition;

                $received = $this->queryPrintCommand(
                    serial: $serial,
                    command: $line,
                    sourceCommand: true,
                    lineNumber: $this->lineNumber,
                    maxLine: $this->lineNumberCount
                );

                $log->debug('PROG: '.$this->lineNumber.' / '.$this->lineNumberCount);

                if ($time - $lastSeen > $this->minPollIntervalSecs - 1) {
                    $lastSeen = $this->printer->updateLastSeen();
                }

                if ($time - $lastCommandUpdate >= self::TERMINAL_REFRESH_INTERVAL_SECS) {
                    $this->printer->setLastCommand(Marlin::getLabel($line));

                    $isActivelyPrinting = $this->printer->isRunning();
                    $timing = $adaptiveEta?->sample($this->lineNumber, $this->lineNumberCount, $isActivelyPrinting);
                    if ($timing) {
                        $timing['hasUnboundedWait'] = $durationEstimate?->hasUnboundedWait ?? false;
                        $this->printer->setPrintTiming($timing);
                    }

                    $stopTimestampSecs = $isActivelyPrinting && isset($timing['printTimeLeft'])
                        ? time() + $timing['printTimeLeft']
                        : null;

                    $serial->tryToAppendNow(
                        lineNumber: $this->lineNumber,
                        maxLine: $this->lineNumberCount,
                        isRunning: $isActivelyPrinting,
                        statistics: $this->printer->getStatistics(),
                        stopTimestampSecs: $stopTimestampSecs
                    );

                    $lastCommandUpdate = time();
                }

                if (time() - $lastStatsUpdate > $statisticsQueryIntervalSecs) {
                    $lastStatsUpdate = time();

                    $statistics = $this->printer->getStatistics();

                    if (isset($statistics['extruders'])) {
                        foreach (array_keys($statistics['extruders']) as $extruderIndex) {
                            try {
                                $log->debug('Trying to refresh statistics...');

                                $temperatureCommand = 'M105';

                                if ($extruderIndex > 0) {
                                    $temperatureCommand .= ' T'.$extruderIndex;
                                }

                                $this->printer->setStatistics(
                                    lines: $this->queryPrintCommand(
                                        serial: $serial,
                                        command: $temperatureCommand,
                                        sourceCommand: false,
                                        lineNumber: $this->lineNumber,
                                        maxLine: $this->lineNumberCount
                                    ),
                                    extruderIndex: $extruderIndex
                                );
                            } catch (PrintRecoveryRequiredException $exception) {
                                throw $exception;
                            }
                        }
                    }
                }

                if ($this->executionToken !== null) {
                    $checkpoint = $this->executionState()->checkpoint((string) $this->printer->_id);
                    $absolutePosition = $checkpoint['state']['position'] ?? $absolutePosition;

                    if (($previousPosition['z'] ?? null) != ($absolutePosition['z'] ?? null)) {
                        $this->printer->incrementCurrentLayer();
                    }

                    $log->debug('POS: '.json_encode($absolutePosition));
                }

                $lastProgressPercentage = round(
                    num: ($this->lineNumber * 100) / $this->lineNumberCount,
                    precision: 2
                );

                if ($lastProgressPercentage > 0) {
                    $lastProgressPercentage = ceil($lastProgressPercentage);
                }

                if ($lastProgressPercentage > 100) {
                    $lastProgressPercentage = 100;
                }

                if ($lastProgressPercentage != $progressPercentage) {
                    $this->queryPrintCommand($serial, "M73 P{$lastProgressPercentage}");

                    $progressPercentage = $lastProgressPercentage;
                }

                if (Str::contains($received, 'ok')) {
                    if ($this->executionToken === null) {
                        $this->lineNumber = $this->printer->incrementCurrentLine();
                    }

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

                unset($buffer[$index]);

                $this->bufferChunk(
                    stream: $this->gcode,
                    buffer: $buffer
                );
            }

            $serial->tryToAppendNow();
            $serial->close();

            $log->info('Job finished.');

            $this->finished(resetPrinter: true);
        } finally {
            $serial->close();
        }
    }

    private function getSerialPluginHooks(): array
    {
        if ($this->serialPluginHooks === null) {
            $this->serialPluginHooks = app(PluginHookCompiler::class)->compileSerialHooks();
        }

        return $this->serialPluginHooks;
    }
}
