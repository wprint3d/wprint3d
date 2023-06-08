<?php

namespace App\Libraries;

use App\Events\PrinterTerminalUpdated;

use App\Models\Configuration;
use App\Models\Printer;

use App\Exceptions\InitializationException;
use App\Exceptions\TimedOutException;

use Illuminate\Cache\Repository;

use Illuminate\Log\Logger;

use Illuminate\Support\Arr;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

use Exception;

class Serial {

    private $fd;

    private string      $fileName;
    private int         $baudRate;
    private int         $terminalMaxLines;

    private Repository  $lockCache;
    private string      $lockKey;

    private ?string $printerId  = null;
    private ?int    $timeout    = null;

    private ?Logger $log = null;

    private string  $terminalBuffer     = '';
    private bool    $terminalAutoAppend = true;

    private array $clocks;
    private array $externalProperties;
    private array $onNewLineActions;

    const TERMINAL_PATH   = '/dev';
    const TERMINAL_PREFIX = 'tty';

    const CACHE_LOCK_SUFFIX = '_nodeLock';
    const CACHE_LOCK_TTL    = 60; // seconds

    const CACHE_REFRESH_RATE_MICROS = 500; // microseconds

    const CONSOLE_EXPECTED_RESPONSE_RATE_MILLIS = 100; // ms

    /**
     * __construct
     *
     * @param  string   $fileName           - the node file name (as in, if you're looking for 'ttyUSB0', you'd write 'USB0')
     * @param  int      $baudRate           - the rate (in bits per second) on which data will be processed
     * @param  ?int     $timeout            - the maximum amount of time that can be spent on a read
     * @param  ?string  $printerId          - the ObjectId of the printer related to this transaction
     * @param  bool     $terminalAutoAppend - whether the terminal should be auto-appended
     * 
     * @throws InitializationException
     * 
     * @return void
     */
    public function __construct(string $fileName, int $baudRate, ?int $timeout = null, ?string $printerId = null, bool $terminalAutoAppend = true) {
        $this->fileName  = $fileName;
        $this->baudRate  = $baudRate;
        $this->printerId = $printerId;

        $this->lockCache = Cache::store();

        $this->lockKey   = $this->fileName . self::CACHE_LOCK_SUFFIX;

        if (Configuration::get('debugSerial')) {
            $this->log = Log::channel('serial');
        }

        $this->terminalMaxLines = Configuration::get('terminalMaxLines');

        $this->configure();

        $this->timeout = $timeout;

        if ($this->timeout === null) {
            $this->timeout = Configuration::get('commandTimeoutSecs');
        }

        if (!$this->fd) {
            throw new InitializationException('Failed to open connection.');
        }

        $this->terminalAutoAppend = $terminalAutoAppend;

        $this->clocks                = [];
        $this->externalProperties    = [];
        $this->onNewLineActions      = [];
    }
    
    /**
     * getProperty
     *
     * @param string $key A dot notation-based property key. 
     * 
     * @return mixed|null
     */
    public function getProperty(string $key) {
        return Arr::get(
            array:  $this->externalProperties,
            key:    $key
        );
    }

    /**
      * setProperty
      *
      * @param  string $key   A dot notation-based property key. 
      * @param  mixed  $value A value of any kind
      * 
      * @return array of properties
      */
    public function setProperty(string $key, mixed $value) {
        return Arr::set(
            array:  $this->externalProperties,
            key:    $key,
            value:  $value
        );
    }
    
    /**
     * onNewLine
     * 
     * DO NOT RUN long-running sentences, use this event to dispatch jobs or
     * to handle extremely fast calls.
     *
     * @param  callable $function A function to chain
     * @return void
     */
    public function onNewLine(callable $function) {
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
     * @param  string   $clockName The name of the clock
     * @param  int      $interval  The interval in which $function should be run (in milliseconds)
     * @param  callable $function  The function to run
     * 
     * @return void
     */
    public function everyBusyMillis(string $clockName, int $interval, callable $function) {
        $this->clocks[ $clockName ] = [
            'lastRun'  => millis(),
            'tickRate' => $interval,
            'callable' => $function
        ];
    }
    
    /**
     * tickClocks
     * 
     * Try to run queued callables in $this->clocks.
     * 
     * @return void
     */
    private function tickClocks() {
        if (!filled( $this->clocks )) return;

        foreach ($this->clocks as $key => $clock) {
            if (millis() - $clock['lastRun'] > $clock['tickRate']) {
                try { $clock['callable'](); }
                catch (Exception $exception) {
                    if ($this->log) {
                        $this->log->error(
                            __METHOD__ . ': couldn\'t run queued callable: ' . $exception->getMessage() . PHP_EOL .
                            $exception->getTraceAsString()
                        );
                    }
                }

                $this->clocks[ $key ]['lastRun'] = millis();
            }
        }
    }

    private function configure() {
        while ($this->lockCache->get($this->lockKey, false)) {
            usleep( self::CACHE_REFRESH_RATE_MICROS );
        } // block

        $this->fd = dio_open(
            self::TERMINAL_PATH . '/' . self::TERMINAL_PREFIX . $this->fileName, // filename
            O_RDWR | O_NONBLOCK | O_ASYNC                                        // flags
        );

        dio_fcntl($this->fd, F_SETFL, O_NONBLOCK | O_ASYNC);

        dio_tcsetattr($this->fd, [
            'baud'   => $this->baudRate,
            'bits'   => 8,
            'stop'   => 1,
            'parity' => 0
        ]);
    }

    private function appendLog(string $message, ?int $lineNumber = null, ?int $maxLine = null) : void {
        if (!$this->printerId) return; 

        try {
            PrinterTerminalUpdated::dispatch(
                $this->printerId,       // printerId
                $message,               // command
                $lineNumber,            // line
                $maxLine,               // maxLine
                $this->terminalMaxLines // terminalMaxLines
            );
        } catch (Exception $exception) {
            if ($this->log) {
                $this->log->warning(
                    __METHOD__ . ': PrinterTerminalUpdated: event dispatch failure: ' . $exception->getMessage() . PHP_EOL .
                    $exception->getTraceAsString()
                );
            }
        }

        $this->terminalBuffer = '';
    }

    private function sendCommand(string $command, ?int $lineNumber = null, ?int $maxLine = null) {
        if ($this->log) {
            $this->log->debug('dio_write: ' . $command);
        }

        if ($this->printerId) {
            $terminalMessage = ' > ' . $command . PHP_EOL;

            if ($this->terminalAutoAppend) {
                $this->appendLog(
                    message:    $terminalMessage,
                    lineNumber: $lineNumber,
                    maxLine:    $maxLine
                );
            } else {
                $this->terminalBuffer .= $terminalMessage;
            }
        }

        dio_write($this->fd, $command . PHP_EOL);

        if ($this->log) {
            $this->log->debug('SENT');
        }
    }
    
    /**
     * readUntilBlank
     *
     * @param  ?int $timeout - custom timeout
     * 
     * @return string
     */
    private function readUntilBlank(?int $timeout = null, ?int $lineNumber = null, ?int $maxLine = null) : string {
        if (!$timeout) {
            $timeout = $this->timeout;
        }

        $result = '';

        $sTime     = time();
        $blankTime = millis();

        $lastLineIndex = 0;

        while (true) {
            $read = dio_read($this->fd);

            $spentBlankingMs = millis() - $blankTime;

            if ($read) {
                if ($this->log) {
                    $this->log->debug('dio_read: ' . $read);
                }

                $result .= $read;

                $sTime     = time();
                $blankTime = millis();

                if (
                    $this->printerId
                    &&
                    (
                        strpos($result, 'busy') !== false // contains a "busy" message
                        ||
                        strpos($result, Printer::MARLIN_TEMPERATURE_INDICATOR) !== false // is a message about temperature
                    )
                ) {
                    $newLastLineIndex = strpos(
                        haystack: $result,
                        needle:   PHP_EOL,
                        offset:   $lastLineIndex + 1
                    );

                    if ($newLastLineIndex !== false)  {
                        $this->appendLog(
                            message:    substr(
                                string: $result,
                                offset: $lastLineIndex,
                                length: ($newLastLineIndex - $lastLineIndex)
                            ),
                            lineNumber: $lineNumber,
                            maxLine:    $maxLine
                        );

                        $lastLineIndex = $newLastLineIndex;

                        foreach ($this->onNewLineActions as $callable) {
                            try { $callable(); }
                            catch (Exception $exception) {
                                if ($this->log) {
                                    $this->log->error(
                                        __METHOD__ . ': onNewLineActions: couldn\'t run queued callable: ' . $exception->getMessage() . PHP_EOL .
                                        $exception->getTraceAsString()
                                    );
                                }
                            }
                        }
                    }
                }
            } else if (
                (
                    (
                        strpos($result, Printer::MARLIN_TEMPERATURE_INDICATOR) !== false // temperature report
                        ||
                        strpos($result, 'paused') !== false // paused for user
                        ||
                        (
                            strpos($result, 'echo')   !== false // command progress
                            &&
                            strpos($result, 'busy')   === false // busy running command
                        )
                    )
                    &&
                    $spentBlankingMs >= self::CONSOLE_EXPECTED_RESPONSE_RATE_MILLIS
                    &&
                    filled( $result )
                )
                ||
                (
                    strpos($result, 'ok') !== false // command finished
                    &&
                    strpos($result, Printer::MARLIN_TEMPERATURE_INDICATOR) === false // not a temperature report
                )
            ) {
                if ($this->log) $this->log->debug("End of output detected: ({$spentBlankingMs} ms without data).");

                break;
            }

            if ($timeout && (time() - $sTime >= $timeout)) break;

            // usleep(1);

            $this->tickClocks();
        }

        $this->tickClocks();

        $spentSecs = time() - $sTime;

        if ($timeout && $spentSecs >= $timeout) {
            throw new TimedOutException("timed out while waiting for a newline after {$spentSecs} seconds were spent trying to get a response.");
        }

        if ($this->log) {
            $this->log->debug( __METHOD__ . ': ' . json_encode($result) );

            $this->log->debug('RECV: ' . $result);
        }

        if ($result[ $lastLineIndex ] != PHP_EOL) {
            $lastLineIndex = 0;
        }

        $terminalMessage = trim(
            substr(
                string: $result,
                offset: $lastLineIndex
            )
        );

        if ($this->terminalAutoAppend) {
            $this->appendLog(
                message:    $terminalMessage,
                lineNumber: $lineNumber,
                maxLine:    $maxLine
            );
        } else {
            $this->terminalBuffer .= $terminalMessage . PHP_EOL;
        }

        return trim($result);
    }

    public function query(?string $command = null, ?int $lineNumber = null, ?int $maxLine = null, ?int $timeout = null) : string {
        $this->lockCache->put(
            key:    $this->lockKey,
            value:  true,
            ttl:    self::CACHE_LOCK_TTL
        );

        $throwable = null;

        $this->tickClocks();

        try {
            if ($command) {
                $this->sendCommand( $command, $lineNumber, $maxLine );
            }

            $result = $this->readUntilBlank(
                timeout:    $timeout,
                lineNumber: $lineNumber,
                maxLine:    $maxLine
            );
        } catch (Exception $exception) {
            $throwable = $exception;
        }

        $this->lockCache->forget( $this->lockKey );

        if ($throwable) throw $throwable;

        return $result;
    }

    public function tryToAppendNow(?int $lineNumber = null, ?int $maxLine = null) {
        if ($this->terminalAutoAppend) return;

        if ($this->terminalBuffer) {
            $this->appendLog(
                message:    $this->terminalBuffer,
                lineNumber: $lineNumber,
                maxLine:    $maxLine
            );
        }
    }

    public static function nodeExists(string $fileName) : bool {
        return file_exists(
            self::TERMINAL_PATH . '/' . self::TERMINAL_PREFIX . $fileName
        );
    }

}

?>
