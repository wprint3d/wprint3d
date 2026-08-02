<?php

namespace App\Libraries;

use App\Events\PrinterTerminalUpdated;
use App\Exceptions\InitializationException;
use App\Exceptions\TimedOutException;
use App\Models\Configuration;
use App\Models\Printer;
use App\Plugins\PluginHookCompiler;
use App\Support\FakeSerial\FakeSerialManager;
use Closure;
use Error;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Log\Logger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class Serial
{
    private $fd = null;

    private string $fileName;

    private int $baudRate;

    private int $terminalMaxLines;

    private ?int $maxTimeBetweenHeartbeatsSecs = null;

    private Repository $lockCache;

    private string $lockKey;

    private ?string $printerId = null;

    private ?int $timeout = null;

    private ?Logger $log = null;

    private string $terminalBuffer = '';

    private bool $terminalAutoAppend = true;

    private array $clocks;

    private array $externalProperties;

    private array $onNewLineActions;

    private array $pluginHooks;

    private array $pendingPluginLineHookContext = [];

    private FakeSerialManager $fakeSerialManager;

    private ?string $fakeSerialConnectionToken = null;

    private bool $closed = false;

    private bool $reconnectRequired = false;

    const TERMINAL_PATH = '/dev';

    const TERMINAL_PREFIX = 'tty';

    const CACHE_LOCK_SUFFIX = '_nodeLock';

    const CACHE_RECOVERY_SUFFIX = '_nodeRecovery';

    const CACHE_LOCK_WAIT_SECS = 5;

    const CACHE_LOCK_MIN_TTL_SECS = 10;

    const CACHE_LOCK_TTL_BUFFER_SECS = 7;

    const RECOVERY_MAX_WAIT_SECS = 5;

    const RECOVERY_QUIET_MILLIS = 200;

    const RECOVERY_MARKER_TTL_SECS = 300;

    const DISCARD_PENDING_INPUT_MAX_MILLIS = 25;

    const DEFAULT_TIMEOUT_SECS = 60;

    const MAX_RESPONSE_BYTES = 1024 * 1024;

    const LIVE_BUFFER_WAIT_NANOS = 8;               // nanoseconds (short sleep to save on CPU cycles)

    const EMPTY_BUFFER_WAIT_NANOS = 8 * 1000 * 1000; // milliseconds to nanoseconds (short sleep to save on CPU cycles)

    const WORKAROUND_HELLBOT_QUEUE_PATTERN = '/echo:enqueueing.*\nok T:.*\n/';

    /**
     * __construct
     *
     * @param  string  $fileName  - the node file name (as in, if you're looking for 'ttyUSB0', you'd write 'USB0')
     * @param  int  $baudRate  - the rate (in bits per second) on which data will be processed
     * @param  ?int  $timeout  - the maximum amount of time allowed without incoming data
     * @param  ?string  $printerId  - the ObjectId of the printer related to this transaction
     * @param  bool  $terminalAutoAppend  - whether the terminal should be auto-appended
     * @return void
     *
     * @throws InitializationException
     */
    public function __construct(string $fileName, int $baudRate, ?int $timeout = null, ?string $printerId = null, bool $terminalAutoAppend = true, array $pluginHooks = [])
    {
        $this->fileName = $fileName;
        $this->baudRate = $baudRate;
        $this->printerId = $printerId;

        $this->lockCache = Cache::store();
        $this->fakeSerialManager = app(FakeSerialManager::class);

        $this->lockKey = $this->fileName.self::CACHE_LOCK_SUFFIX;

        if (Configuration::get('debugSerial')) {
            $this->log = Log::channel('serial');
        }

        $this->terminalMaxLines = Configuration::get('terminalMaxLines');

        $this->configure();

        $this->timeout = $timeout;

        if ($this->timeout === null) {
            $this->timeout = Configuration::get('commandTimeoutSecs');
        }

        if (! $this->fd && $this->fakeSerialConnectionToken === null) {
            throw new InitializationException('Failed to open connection.');
        }

        $this->terminalAutoAppend = $terminalAutoAppend;

        $this->clocks = [];
        $this->externalProperties = [];
        $this->onNewLineActions = [];
        $this->pluginHooks = $this->resolvePluginHooks($pluginHooks);

        $this->registerPluginHooks();

        if ($this->log !== null) {
            $this->log->debug(__METHOD__.': created instance with params: '.json_encode(func_get_args()));
        }

        $this->maxTimeBetweenHeartbeatsSecs = Configuration::get('lastSeenThresholdSecs');
    }

    private function resolvePluginHooks(array $pluginHooks = []): array
    {
        if ($pluginHooks === []) {
            $pluginHooks = app(PluginHookCompiler::class)->compileSerialHooks();
        }

        return [
            'serial.command.before_send' => $this->resolvePluginHookCallable($pluginHooks['serial.command.before_send'] ?? null),
            'serial.line.received' => $this->resolvePluginHookCallable($pluginHooks['serial.line.received'] ?? null),
            'serial.command.response_received' => $this->resolvePluginHookCallable($pluginHooks['serial.command.response_received'] ?? null),
        ];
    }

    private function resolvePluginHookCallable(?callable $pluginHook = null): Closure
    {
        if ($pluginHook !== null) {
            return Closure::fromCallable($pluginHook);
        }

        return static fn (array $context = []): array => [];
    }

    private function registerPluginHooks(): void
    {
        $this->onNewLine(function (): void {
            if (empty($this->pendingPluginLineHookContext)) {
                return;
            }

            $this->dispatchPluginHook('serial.line.received', $this->pendingPluginLineHookContext);
        });
    }

    private function dispatchPluginHook(string $hook, array $context = []): array
    {
        return ($this->pluginHooks[$hook])($context);
    }

    public function __destruct()
    {
        if (! $this->terminalAutoAppend) {
            $this->tryToAppendNow();
        }

        $this->close();
    }

    public function close(): void
    {
        $this->disconnectConnection();
        $this->closed = true;
        $this->reconnectRequired = false;
    }

    private function disconnectConnection(): void
    {
        if ($this->fakeSerialConnectionToken !== null) {
            $this->fakeSerialManager->disconnect($this->fileName, $this->fakeSerialConnectionToken);
            $this->fakeSerialConnectionToken = null;
        }

        if ($this->fd) {
            dio_close($this->fd);
            $this->fd = null;
        }
    }

    private function invalidateConnection(): void
    {
        $this->disconnectConnection();
        $this->closed = false;
        $this->reconnectRequired = true;
    }

    private function ensureConnected(): void
    {
        if ($this->closed) {
            throw new InitializationException('The serial connection has been closed.');
        }

        if ($this->reconnectRequired) {
            $this->configure();
            $this->reconnectRequired = false;
        }

        if (! $this->fd && $this->fakeSerialConnectionToken === null) {
            throw new InitializationException('The serial printer is not connected.');
        }
    }

    /**
     * getProperty
     *
     * @param  string  $key  A dot notation-based property key.
     * @return mixed|null
     */
    public function getProperty(string $key)
    {
        return Arr::get(
            array: $this->externalProperties,
            key: $key
        );
    }

    /**
     * setProperty
     *
     * @param  string  $key  A dot notation-based property key.
     * @param  mixed  $value  A value of any kind
     * @return array of properties
     */
    public function setProperty(string $key, mixed $value)
    {
        return Arr::set(
            array: $this->externalProperties,
            key: $key,
            value: $value
        );
    }

    /**
     * onNewLine
     *
     * DO NOT RUN long-running sentences, use this event to dispatch jobs or
     * to handle extremely fast calls.
     *
     * @param  callable  $function  A function to chain
     * @return void
     */
    public function onNewLine(callable $function)
    {
        $this->onNewLineActions[] = $function;
    }

    /**
     * everyBusyMillis
     *
     * DO NOT RUN long-running sentences, use this event to dispatch jobs or
     * to handle extremely fast calls.
     *
     * These clocks are tried and run while busy on long-running tasks such as
     * query().
     *
     * @param  string  $clockName  The name of the clock
     * @param  int  $interval  The interval in which $function should be run (in milliseconds)
     * @param  callable  $function  The function to run
     * @return void
     */
    public function everyBusyMillis(string $clockName, int $interval, callable $function)
    {
        $this->clocks[$clockName] = [
            'lastRun' => millis(),
            'tickRate' => $interval,
            'callable' => $function,
        ];
    }

    /**
     * tickClocks
     *
     * Try to run queued callables in $this->clocks.
     *
     * @param  int  $millis  optional, pass pre-rendered for better performance
     * @return void
     */
    private function tickClocks($millis = null)
    {
        foreach ($this->clocks as $key => $clock) {
            if ($millis === null) {
                $millis = millis();
            }

            if ($millis - $clock['lastRun'] > $clock['tickRate']) {
                if ($this->log) {
                    $this->log->debug(__METHOD__.": {$key}: the clock has ticked! - millis = {$millis}, lastRun = {$clock['lastRun']}, tickRate = {$clock['tickRate']}");
                }

                $this->clocks[$key]['lastRun'] = $millis;

                try {
                    $clock['callable']();
                } catch (Throwable $throwable) {
                    if ($this->log) {
                        $this->log->error(
                            __METHOD__.': couldn\'t run queued callable: '.$throwable->getMessage().PHP_EOL.
                            $throwable->getTraceAsString()
                        );
                    }
                } catch (Error $error) {
                    if ($this->log) {
                        $this->log->error(
                            __METHOD__.': A PHP core error occurred while trying to run a queued callable: '.$error->getMessage().PHP_EOL.
                            $error->getTraceAsString()
                        );
                    }
                }
            }
        }
    }

    /**
     * blockWhileLocking
     *
     * Blocks the current thread while trying to acquire a lock, then, returns
     * an instance of Lock that supports release().
     */
    private function blockWhileLocking(int $lockTtlSecs): Lock
    {
        $lock = $this->lockCache->lock($this->lockKey, $lockTtlSecs);

        try {
            $lock->block(self::CACHE_LOCK_WAIT_SECS);
        } catch (LockTimeoutException $lockTimeoutException) {
            if ($this->log) {
                $this->log->warning(
                    __METHOD__.': timed out waiting for the serial port to free up: '.$lockTimeoutException->getMessage().PHP_EOL.
                    $lockTimeoutException->getTraceAsString()
                );
            }

            throw $lockTimeoutException;
        }

        return $lock;
    }

    private function configure()
    {
        $lock = $this->blockWhileLocking(self::CACHE_LOCK_MIN_TTL_SECS);

        try {
            if ($this->fakeSerialManager->nodeExists($this->fileName)) {
                $this->fakeSerialConnectionToken = $this->fakeSerialManager->connect($this->fileName, $this->baudRate);
                $this->closed = false;

                return;
            }

            $this->fd = dio_open(
                self::TERMINAL_PATH.'/'.self::TERMINAL_PREFIX.$this->fileName, // filename
                O_RDWR | O_NONBLOCK | O_ASYNC                                        // flags
            );

            dio_fcntl($this->fd, F_SETFL, O_NONBLOCK | O_ASYNC);

            dio_tcsetattr($this->fd, [
                'baud' => $this->baudRate,
                'bits' => 8,
                'stop' => 1,
                'parity' => 0,
            ]);

            $this->closed = false;
        } catch (Throwable $exception) {
            $this->disconnectConnection();

            if ($this->log) {
                $this->log->error(
                    "{$this->fileName}: couldn't configure: {$exception->getMessage()}".PHP_EOL.
                    PHP_EOL.
                    $exception->getTraceAsString()
                );
            }

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    private function appendLog(string $message, ?int $lineNumber = null, ?int $maxLine = null, ?bool $isRunning = null, ?array $statistics = null, mixed $stopTimestampSecs = null): void
    {
        if (
            ! $this->printerId
            ||
            ! trim($message)
        ) {
            return;
        }

        if ($this->log) {
            $this->log->debug(__METHOD__.': appending log: '.$message);
        }

        if (! $stopTimestampSecs) {
            Log::debug(__METHOD__.': stopTimestampSecs is not numeric: '.json_encode($stopTimestampSecs));

            $stopTimestampSecs = null;
        }

        try {
            PrinterTerminalUpdated::dispatch(
                $this->printerId,           // printerId
                $message,                   // command
                $lineNumber,                // line
                $maxLine,                   // maxLine
                $this->terminalMaxLines,    // terminalMaxLines
                $isRunning,                 // isRunning
                $statistics,                // statistics
                $stopTimestampSecs,         // stopTimestampSecs
                $this->maxTimeBetweenHeartbeatsSecs // thresholdSecs
            );
        } catch (Throwable $throwable) {
            if ($this->log) {
                $this->log->warning(
                    __METHOD__.': PrinterTerminalUpdated: event dispatch failure: '.$throwable->getMessage().PHP_EOL.
                    $throwable->getTraceAsString()
                );
            }
        }

        $this->terminalBuffer = '';
    }

    private function sendCommand(string $command, ?int $lineNumber = null, ?int $maxLine = null)
    {
        $this->dispatchPluginHook('serial.command.before_send', [
            'printerId' => $this->printerId,
            'command' => $command,
            'lineNumber' => $lineNumber,
            'maxLine' => $maxLine,
        ]);

        if ($this->log) {
            $this->log->debug('dio_write: '.$command);
        }

        if ($this->printerId) {
            $terminalMessage = ' > '.$command.PHP_EOL;

            $this->terminalBuffer .= $terminalMessage;

            if ($this->terminalAutoAppend) {
                $this->appendLog(
                    message: $this->terminalBuffer,
                    lineNumber: $lineNumber,
                    maxLine: $maxLine
                );
            }
        }

        if ($this->fakeSerialConnectionToken === null) {
            dio_write($this->fd, $command.PHP_EOL);
        }

        if ($this->log) {
            $this->log->debug('SENT');
        }
    }

    private function isTemperatureMessage(string $message): bool
    {
        return strpos($message, Printer::MARLIN_TEMPERATURE_INDICATOR) !== false;
    }

    private function appendIncomingLine(string $message, ?string $command = null, ?int $lineNumber = null, ?int $maxLine = null): void
    {
        $message = trim($message);

        if ($message === '' || ! $this->printerId) {
            return;
        }

        if ($this->isTemperatureMessage($message)) {
            $extruderIndex = 0;

            if ($command !== null && strpos($command, 'M105 T') !== false) {
                $extruderIndex = (int) str_replace(
                    search: 'M105 T',
                    replace: '',
                    subject: $command
                );
            }

            Printer::setStatisticsOf(
                printerId: $this->printerId,
                lines: $message,
                extruderIndex: $extruderIndex
            );
        }

        $this->terminalBuffer .= $message.PHP_EOL;

        if (
            $this->terminalAutoAppend
            ||
            strpos($message, 'busy') !== false
            ||
            $this->isTemperatureMessage($message)
        ) {
            $this->appendLog(
                message: $this->terminalBuffer,
                lineNumber: $lineNumber,
                maxLine: $maxLine
            );
        }

        $this->pendingPluginLineHookContext = [
            'printerId' => $this->printerId,
            'command' => $command,
            'line' => $message,
            'lineNumber' => $lineNumber,
            'maxLine' => $maxLine,
        ];

        foreach ($this->onNewLineActions as $callable) {
            try {
                $callable();
            } catch (Throwable $throwable) {
                if ($this->log) {
                    $this->log->error(
                        __METHOD__.': onNewLineActions: couldn\'t run queued callable: '.$throwable->getMessage().PHP_EOL.
                        $throwable->getTraceAsString()
                    );
                }
            }
        }

        $this->pendingPluginLineHookContext = [];
    }

    private function resolveTimeout(?int $timeout = null): int
    {
        $resolvedTimeout = $timeout;

        if (! $resolvedTimeout) {
            $resolvedTimeout = $this->timeout;
        }

        if (! $resolvedTimeout || $resolvedTimeout < 1) {
            return self::DEFAULT_TIMEOUT_SECS;
        }

        return $resolvedTimeout;
    }

    private function deadlineMillis(int $timeout): float
    {
        return millis() + ($timeout * 1000);
    }

    private function renewDeadline(float &$startedAtMillis, float &$deadlineMillis, int $timeout): void
    {
        $startedAtMillis = millis();
        $deadlineMillis = $startedAtMillis + ($timeout * 1000);
    }

    private function throwIfTimedOut(float $startedAtMillis, float $deadlineMillis, int $timeout): void
    {
        if (millis() < $deadlineMillis) {
            return;
        }

        $spentSecs = round((millis() - $startedAtMillis) / 1000, 3);

        throw new TimedOutException("timed out after {$spentSecs} seconds while waiting for a response (limit: {$timeout} seconds).");
    }

    private function appendResponseChunk(string &$response, string $chunk): void
    {
        if (strlen($response) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
            throw new InitializationException('Serial response exceeded the '.self::MAX_RESPONSE_BYTES.' byte limit.');
        }

        $response .= $chunk;
    }

    private function validateResponse(string $response): void
    {
        if (! mb_check_encoding($response, 'UTF-8')) {
            throw new InitializationException('Serial response contains invalid UTF-8 data.');
        }
    }

    private function recoveryKey(): string
    {
        return $this->fileName.self::CACHE_RECOVERY_SUFFIX;
    }

    private function markConnectionForRecovery(int $timeout): void
    {
        $waitSecs = min(self::RECOVERY_MAX_WAIT_SECS, max(1, $timeout));

        $this->lockCache->put(
            $this->recoveryKey(),
            ['waitUntilMillis' => millis() + ($waitSecs * 1000)],
            self::RECOVERY_MARKER_TTL_SECS
        );
    }

    private function discardPendingInput(): bool
    {
        $discardedBytes = 0;
        $deadlineMillis = millis() + self::DISCARD_PENDING_INPUT_MAX_MILLIS;

        while (millis() < $deadlineMillis) {
            $read = dio_read($this->fd);

            if (! $read) {
                break;
            }

            $discardedBytes += strlen($read);

            if ($discardedBytes >= self::MAX_RESPONSE_BYTES) {
                break;
            }
        }

        if ($discardedBytes > 0 && $this->log) {
            $this->log->warning(__METHOD__.": discarded {$discardedBytes} stale input bytes before sending a command.");
        }

        return $discardedBytes > 0;
    }

    private function recoverPendingInput(): void
    {
        $recovery = $this->lockCache->get($this->recoveryKey());

        if (! is_array($recovery)) {
            $this->discardPendingInput();

            return;
        }

        $waitUntilMillis = min(
            (float) ($recovery['waitUntilMillis'] ?? millis()),
            millis() + (self::RECOVERY_MAX_WAIT_SECS * 1000)
        );
        $deadlineMillis = millis()
            + (self::RECOVERY_MAX_WAIT_SECS * 1000)
            + self::RECOVERY_QUIET_MILLIS
            + self::DISCARD_PENDING_INPUT_MAX_MILLIS;

        while (millis() < $waitUntilMillis) {
            $this->discardPendingInput();
            $this->tickClocks();

            time_nanosleep(seconds: 0, nanoseconds: 10 * 1000 * 1000);

            if (millis() >= $deadlineMillis) {
                throw new InitializationException('Serial input did not settle after the previous failed transaction.');
            }
        }

        $quietSinceMillis = millis();

        while ((millis() - $quietSinceMillis) < self::RECOVERY_QUIET_MILLIS) {
            if ($this->discardPendingInput()) {
                $quietSinceMillis = millis();
            }

            $this->tickClocks();

            if (millis() >= $deadlineMillis) {
                throw new InitializationException('Serial input did not settle after the previous failed transaction.');
            }

            time_nanosleep(seconds: 0, nanoseconds: 10 * 1000 * 1000);
        }

        $this->lockCache->forget($this->recoveryKey());
    }

    private function waitForFakeSerialDelay(int $delayMs, float $startedAtMillis, float $deadlineMillis, int $timeout): void
    {
        $delayDeadlineMillis = millis() + $delayMs;

        while (millis() < $delayDeadlineMillis) {
            $this->throwIfTimedOut($startedAtMillis, $deadlineMillis, $timeout);

            $remainingDelayMs = $delayDeadlineMillis - millis();
            $remainingTimeoutMs = $deadlineMillis - millis();
            $sleepMs = (int) max(1, min(10, $remainingDelayMs, $remainingTimeoutMs));

            time_nanosleep(
                seconds: 0,
                nanoseconds: $sleepMs * 1000 * 1000
            );

            $this->tickClocks();
        }

        $this->throwIfTimedOut($startedAtMillis, $deadlineMillis, $timeout);
    }

    private function queryFakeSerial(?string $command = null, ?int $lineNumber = null, ?int $maxLine = null, ?int $timeout = null): string
    {
        if ($this->fakeSerialConnectionToken === null) {
            throw new InitializationException('The fake serial printer is not connected.');
        }

        $timeout = $this->resolveTimeout($timeout);
        $startedAtMillis = millis();
        $deadlineMillis = $this->deadlineMillis($timeout);

        $result = $this->fakeSerialManager->transact(
            node: $this->fileName,
            baudRate: $this->baudRate,
            token: $this->fakeSerialConnectionToken,
            command: $command ?? '',
            timeout: $timeout
        );

        $this->throwIfTimedOut($startedAtMillis, $deadlineMillis, $timeout);

        $response = '';

        foreach ($result['lines'] as $line) {
            $delayMs = (int) ($line['delayMs'] ?? 0);

            if ($delayMs > 0) {
                $this->waitForFakeSerialDelay($delayMs, $startedAtMillis, $deadlineMillis, $timeout);
            }

            $this->tickClocks();
            $this->throwIfTimedOut($startedAtMillis, $deadlineMillis, $timeout);

            $message = $line['text'] ?? '';
            $this->validateResponse($message);

            $this->renewDeadline($startedAtMillis, $deadlineMillis, $timeout);

            $separator = $response === '' ? '' : PHP_EOL;
            $this->appendResponseChunk($response, $separator.$message);
            $this->appendIncomingLine(
                message: $message,
                command: $command,
                lineNumber: $lineNumber,
                maxLine: $maxLine
            );
        }

        $fullResponse = trim($response);
        $this->validateResponse($fullResponse);

        $this->dispatchPluginHook('serial.command.response_received', [
            'printerId' => $this->printerId,
            'command' => $command,
            'response' => $fullResponse,
            'lineNumber' => $lineNumber,
            'maxLine' => $maxLine,
        ]);

        return $fullResponse;
    }

    /**
     * readUntilBlank
     *
     * @param  ?int  $timeout  - custom timeout
     */
    private function readUntilBlank(?int $timeout = null, ?int $lineNumber = null, ?int $maxLine = null, ?string $command = null): string
    {
        $timeout = $this->resolveTimeout($timeout);

        $result = '';

        $startedAtMillis = millis();
        $deadlineMillis = $this->deadlineMillis($timeout);
        $blankTime = millis();

        $lastLineIndex = 0;

        while (true) {
            $read = dio_read($this->fd);

            $millis = millis();

            $spentBlankingMs = $millis - $blankTime;

            if ($read) {
                $this->renewDeadline($startedAtMillis, $deadlineMillis, $timeout);

                if ($this->log) {
                    $this->log->debug('dio_read: '.$read);
                }

                $this->appendResponseChunk($result, $read);

                /*
                 * Workaround for Hellbot's broken firmware:
                 *
                 * Automatic interval-based enqueueing of M105.
                 *
                 * We're gonna remove it in order to avoid having such output
                 * break the parser.
                 *
                 * If the command is M105, however, we're gonna consider this a
                 * true "ok", since we can't really tell the difference. Oops!
                 *
                 * tl;dr: Hellbot, please, fix it. :)
                 *
                 * Example:
                 *
                 * echo:enqueueing "M105"
                 * ok T:39.54 /40.00 B:16.71 /0.00 T0:39.54 /40.00 T1:39.21 /0.00 @:21 B@:0 @0:21 @1:0
                 */
                if (! empty($command) && ! str_starts_with($command, 'M105')) {
                    $result = preg_replace(
                        pattern: self::WORKAROUND_HELLBOT_QUEUE_PATTERN,
                        replacement: '',
                        subject: $result
                    );
                }

                $blankTime = $millis;

                if (
                    $this->printerId
                    &&
                    (
                        strpos($result, 'busy') !== false // contains a "busy" message
                        ||
                        strpos($result, Printer::MARLIN_TEMPERATURE_INDICATOR) !== false // is a message about temperature
                    )
                ) {
                    $newLastLineIndex = false;

                    if (isset($result[$lastLineIndex + 1])) {
                        $newLastLineIndex = strpos(
                            haystack: $result,
                            needle: PHP_EOL,
                            offset: $lastLineIndex + 1
                        );
                    }

                    if ($newLastLineIndex !== false) {
                        $message = substr(
                            string: $result,
                            offset: $lastLineIndex,
                            length: ($newLastLineIndex - $lastLineIndex)
                        );
                        $this->validateResponse($message);

                        // Is querying temperature?
                        if (strpos($message, Printer::MARLIN_TEMPERATURE_INDICATOR) !== false) {
                            $extruderIndex = 0;

                            // Is selecting a specific extruder?
                            if (strpos($command, 'M105 T') !== false) {
                                $extruderIndex = (int) str_replace(
                                    search: 'M105 T',
                                    replace: '',
                                    subject: $command
                                );
                            }

                            Printer::setStatisticsOf(
                                printerId: $this->printerId,
                                lines: $message,
                                extruderIndex: $extruderIndex
                            );
                        }

                        $this->terminalBuffer .= $message;

                        // Prevent missing newlines between messages
                        if ($message[strlen($message) - 1] != PHP_EOL) {
                            $this->terminalBuffer .= PHP_EOL;
                        }

                        if ($this->terminalAutoAppend || strpos($this->terminalBuffer, 'busy') !== false) {
                            $this->appendLog(
                                message: $this->terminalBuffer,
                                lineNumber: $lineNumber,
                                maxLine: $maxLine
                            );
                        }

                        $lastLineIndex = $newLastLineIndex;

                        $this->pendingPluginLineHookContext = [
                            'printerId' => $this->printerId,
                            'command' => $command,
                            'line' => trim($message),
                            'lineNumber' => $lineNumber,
                            'maxLine' => $maxLine,
                        ];

                        foreach ($this->onNewLineActions as $callable) {
                            try {
                                $callable();
                            } catch (Throwable $throwable) {
                                if ($this->log) {
                                    $this->log->error(
                                        __METHOD__.': onNewLineActions: couldn\'t run queued callable: '.$throwable->getMessage().PHP_EOL.
                                        $throwable->getTraceAsString()
                                    );
                                }
                            }
                        }

                        $this->pendingPluginLineHookContext = [];
                    }
                }

                $read = '';
            } elseif (! empty($result)) {
                $lastLine = substr(
                    string: $result,
                    offset: strrpos($result, PHP_EOL, 1)
                );

                if (! empty($lastLine)) {
                    $lastLine = $result;
                }

                if (
                    str_ends_with($result, PHP_EOL)
                    &&
                    (
                        strpos($result, 'ok') !== false // finished successfully
                        ||
                        (
                            (
                                strpos($lastLine, 'echo') !== false // (in last line) contains echo
                                &&
                                strpos($lastLine, 'echo:enqueueing') === false // (in last line) doesn't contain a queueing request
                            )
                            &&
                            strpos($lastLine, 'paused') === false // (in last line) not paused for user
                            &&
                            strpos($lastLine, 'busy') === false // (in last line) not busy
                        )
                    )
                ) {
                    $this->throwIfTimedOut($startedAtMillis, $deadlineMillis, $timeout);

                    if ($this->log) {
                        $this->log->debug("End of output detected: ({$spentBlankingMs} ms without data).");
                    }

                    break;
                }
            }

            $this->tickClocks($millis);

            $this->throwIfTimedOut($startedAtMillis, $deadlineMillis, $timeout);

            if (! $read) {
                /*
                 * Halt thread while waiting for more data, then, call continue
                 * in order to try again.
                 */
                time_nanosleep(
                    seconds: 0,
                    nanoseconds: self::EMPTY_BUFFER_WAIT_NANOS
                );
            } else {
                // Forcefully halt for LIVE_BUFFER_WAIT_NANOS
                time_nanosleep(
                    seconds: 0,
                    nanoseconds: self::LIVE_BUFFER_WAIT_NANOS
                );
            }
        }

        $this->tickClocks($millis);

        $this->validateResponse($result);

        if ($this->log) {
            $this->log->debug(__METHOD__.': '.json_encode($result));

            $this->log->debug('RECV: '.$result);
        }

        if (
            ! isset($result[$lastLineIndex])
            ||
            $result[$lastLineIndex] != PHP_EOL
        ) {
            $lastLineIndex = 0;
        }

        $terminalMessage = trim(
            substr(
                string: $result,
                offset: $lastLineIndex
            )
        );

        if ($this->printerId) {
            $this->terminalBuffer .= $terminalMessage.PHP_EOL;

            if ($this->terminalAutoAppend) {
                $this->appendLog(
                    message: $this->terminalBuffer,
                    lineNumber: $lineNumber,
                    maxLine: $maxLine
                );
            }
        }

        $this->dispatchPluginHook('serial.command.response_received', [
            'printerId' => $this->printerId,
            'command' => $command,
            'response' => trim($result),
            'lineNumber' => $lineNumber,
            'maxLine' => $maxLine,
        ]);

        return trim($result);
    }

    public function query(?string $command = null, ?int $lineNumber = null, ?int $maxLine = null, ?int $timeout = null): string
    {
        $timeout = $this->resolveTimeout($timeout);
        $this->ensureConnected();

        $lockTtlSecs = max(
            self::CACHE_LOCK_MIN_TTL_SECS,
            $timeout + self::CACHE_LOCK_TTL_BUFFER_SECS
        );
        $lock = $this->blockWhileLocking($lockTtlSecs);
        $isRealSerial = $this->fakeSerialConnectionToken === null;
        $transactionStarted = false;

        try {
            if ($isRealSerial && $command) {
                $this->recoverPendingInput();
            }

            $this->tickClocks();

            if ($command) {
                $transactionStarted = true;
                $this->sendCommand($command, $lineNumber, $maxLine);
            }

            $result =
                $this->fakeSerialConnectionToken !== null
                    ? $this->queryFakeSerial(
                        command: $command,
                        timeout: $timeout,
                        lineNumber: $lineNumber,
                        maxLine: $maxLine
                    )
                    : $this->readUntilBlank(
                        command: $command,
                        timeout: $timeout,
                        lineNumber: $lineNumber,
                        maxLine: $maxLine
                    );
        } catch (Throwable $throwable) {
            if ($isRealSerial && $transactionStarted) {
                try {
                    $this->markConnectionForRecovery($timeout);
                } catch (Throwable $recoveryThrowable) {
                    if ($this->log) {
                        $this->log->warning(__METHOD__.': could not mark the serial connection for recovery: '.$recoveryThrowable->getMessage());
                    }
                }
            }

            $this->invalidateConnection();

            throw $throwable;
        } finally {
            $lock->release();
        }

        return $result;
    }

    public function tryToAppendNow(?int $lineNumber = null, ?int $maxLine = null, ?bool $isRunning = null, ?array $statistics = null, mixed $stopTimestampSecs = null)
    {
        if (! is_numeric($stopTimestampSecs)) {
            Log::debug(__METHOD__.': stopTimestampSecs is not numeric: '.json_encode($stopTimestampSecs));

            $stopTimestampSecs = null;
        }

        if ($this->terminalAutoAppend) {
            return;
        }

        if ($this->terminalBuffer) {
            $this->appendLog(
                message: $this->terminalBuffer,
                lineNumber: $lineNumber,
                maxLine: $maxLine,
                isRunning: $isRunning,
                statistics: $statistics,
                stopTimestampSecs: $stopTimestampSecs
            );
        }
    }

    public static function nodeExists(?string $fileName): bool
    {
        if (! is_string($fileName) || trim($fileName) === '') {
            return false;
        }

        return
            file_exists(
                self::TERMINAL_PATH.'/'.self::TERMINAL_PREFIX.$fileName
            )
            ||
            app(FakeSerialManager::class)->nodeExists($fileName);
    }
}
