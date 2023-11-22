<?php

namespace App\Libraries;

use App\Events\PrinterTerminalUpdated;

use App\Models\Configuration;
use App\Models\Printer;

use App\Exceptions\InitializationException;
use App\Exceptions\TimedOutException;

use Illuminate\Log\Logger;

use Illuminate\Support\Arr;

use Illuminate\Support\Facades\Log;

use Error;
use Exception;

class Serial {

    private $fd;

    private string      $fileName;
    private int         $baudRate;
    private int         $terminalMaxLines;

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

    const LIVE_BUFFER_WAIT_NANOS  = 8;               // nanoseconds (short sleep to save on CPU cycles)
    const EMPTY_BUFFER_WAIT_NANOS = 8 * 1000 * 1000; // milliseconds to nanoseconds (short sleep to save on CPU cycles)

    const WORKAROUND_HELLBOT_QUEUE_PATTERN = '/echo:enqueueing.*\nok T:.*\n/';

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

    public function __destruct() {
        if ($this->fd) {
            dio_close( $this->fd );
        }
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
     * @param  int $millis optional, pass pre-rendered for better performance
     * 
     * @return void
     */
    private function tickClocks($millis = null) {
        foreach ($this->clocks as $key => $clock) {
            if ($millis === null) {
                $millis = millis();
            }

            if ($millis - $clock['lastRun'] > $clock['tickRate']) {
                $this->clocks[ $key ]['lastRun'] = $millis;

                try { $clock['callable'](); }
                catch (Exception $exception) {
                    if ($this->log) {
                        $this->log->error(
                            __METHOD__ . ': couldn\'t run queued callable: ' . $exception->getMessage() . PHP_EOL .
                            $exception->getTraceAsString()
                        );
                    }
                }
                catch (Error $error) {
                    if ($this->log) {
                        $this->log->error(
                            __METHOD__ . ': A PHP core error occurred while trying to run a queued callable: ' . $error->getMessage() . PHP_EOL .
                            $error->getTraceAsString()
                        );
                    }
                }
            }
        }
    }

    private function configure() {
        $this->fd = dio_open(
            self::TERMINAL_PATH . '/' . self::TERMINAL_PREFIX . $this->fileName, // filename
            O_RDWR | O_NONBLOCK | O_ASYNC                                        // flags
        );

        dio_fcntl($this->fd, F_SETLKW, [ 'type' => F_WRLCK ]);
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
    private function readUntilBlank(?int $timeout = null, ?int $lineNumber = null, ?int $maxLine = null, ?string $command = null) : string {
        if (!$timeout) {
            $timeout = $this->timeout;
        }

        $result = '';

        $sTime     = time();
        $blankTime = millis();

        $lastLineIndex = 0;

        while (true) {
            $read = dio_read($this->fd);

            $millis = millis();

            $spentBlankingMs = $millis - $blankTime;

            if ($read) {
                if ($this->log) {
                    $this->log->debug('dio_read: ' . $read);
                }

                $result .= $read;

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
                if (!empty( $command ) && !str_starts_with($command, 'M105')) {
                    $result = preg_replace(
                        pattern:     self::WORKAROUND_HELLBOT_QUEUE_PATTERN,
                        replacement: '',
                        subject:     $result
                    );
                }

                $sTime      = time();
                $blankTime  = $millis;

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

                    if (isset( $result[ $lastLineIndex + 1 ] )) {
                        $newLastLineIndex = strpos(
                            haystack: $result,
                            needle:   PHP_EOL,
                            offset:   $lastLineIndex + 1
                        );
                    }

                    if ($newLastLineIndex !== false)  {
                        $message = substr(
                            string: $result,
                            offset: $lastLineIndex,
                            length: ($newLastLineIndex - $lastLineIndex)
                        );

                        // Is querying temperature?
                        if (strpos( $message, Printer::MARLIN_TEMPERATURE_INDICATOR ) !== false) {
                            $extruderIndex = 0;

                            // Is selecting a specific extruder?
                            if (strpos( $command, 'M105 T' ) !== false) {
                                $extruderIndex = (int) str_replace(
                                    search:  'M105 T',
                                    replace: '',
                                    subject: $command
                                );
                            }

                            Printer::setStatisticsOf(
                                printerId:      $this->printerId,
                                lines:          $message,
                                extruderIndex:  $extruderIndex
                            );
                        }

                        $this->appendLog(
                            message:    $message,
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

                $read = '';
            } else if (!empty( $result )) {
                $lastLine = substr(
                    string: $result,
                    offset: strrpos( $result, PHP_EOL, 1 )
                );

                if (!empty( $lastLine )) {
                    $lastLine = $result;
                }

                if (
                    str_ends_with( $result, PHP_EOL )
                    &&
                    (
                        strpos( $result, 'ok' ) !== false // finished successfully
                        ||
                        (
                            (
                                strpos( $lastLine, 'echo' )             !== false // (in last line) contains echo
                                &&
                                strpos( $lastLine, 'echo:enqueueing' )  === false // (in last line) doesn't contain a queueing request
                            )
                            &&
                            strpos( $lastLine, 'paused' ) === false // (in last line) not paused for user
                            &&
                            strpos( $lastLine, 'busy' )   === false // (in last line) not busy
                        )
                    )
                ) {
                    if ($this->log) $this->log->debug("End of output detected: ({$spentBlankingMs} ms without data).");

                    break;
                }
            }

            $this->tickClocks( $millis );

            if ($timeout && (time() - $sTime >= $timeout)) break;

            if (!$read) {
                /* 
                 * Halt thread while waiting for more data, then, call continue
                 * in order to try again.
                 */
                time_nanosleep(
                    seconds:     0,
                    nanoseconds: self::EMPTY_BUFFER_WAIT_NANOS
                );
            } else {
                // Forcefully halt for LIVE_BUFFER_WAIT_NANOS
                time_nanosleep(
                    seconds:     0,
                    nanoseconds: self::LIVE_BUFFER_WAIT_NANOS
                );
            }
        }

        $this->tickClocks( $millis );

        $spentSecs = time() - $sTime;

        if ($timeout && $spentSecs >= $timeout) {
            throw new TimedOutException("timed out while waiting for a newline after {$spentSecs} seconds were spent trying to get a response.");
        }

        if ($this->log) {
            $this->log->debug( __METHOD__ . ': ' . json_encode($result) );

            $this->log->debug('RECV: ' . $result);
        }

        if (
            !isset($result[ $lastLineIndex ])
            ||
            $result[ $lastLineIndex ] != PHP_EOL
        ) { $lastLineIndex = 0; }

        $terminalMessage = trim(
            substr(
                string: $result,
                offset: $lastLineIndex
            )
        );

        if ($this->printerId && $this->terminalAutoAppend) {
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
        $throwable = null;

        $this->tickClocks();

        try {
            if ($command) {
                $this->sendCommand( $command, $lineNumber, $maxLine );
            }

            $result = $this->readUntilBlank(
                command:    $command,
                timeout:    $timeout,
                lineNumber: $lineNumber,
                maxLine:    $maxLine
            );
        } catch (Exception $exception) {
            $throwable = $exception;
        }

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
