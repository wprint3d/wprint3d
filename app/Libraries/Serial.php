<?php

namespace App\Libraries;

use App\Events\PrinterTerminalUpdated;

use App\Models\Configuration;
use App\Models\Printer;

use App\Exceptions\InitializationException;
use App\Exceptions\TimedOutException;

use Illuminate\Cache\Repository;

use Illuminate\Log\Logger;

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

    const TERMINAL_PATH   = '/dev';
    const TERMINAL_PREFIX = 'tty';

    const CACHE_LOCK_SUFFIX = '_nodeLock';
    const CACHE_LOCK_TTL    = 60; // seconds

    const CACHE_REFRESH_RATE_MICROS = 500; // microseconds

    const CONSOLE_EXPECTED_RESPONSE_RATE_MILLIS = 16; // ms

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

        $line = '';

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
                    $this->tryToAppendNow(
                        lineNumber: $lineNumber,
                        maxLine:    $maxLine
                    );

                    while (isset($result[ $lastLineIndex ])) {
                        if ($result[ $lastLineIndex ] == PHP_EOL && ($line = trim( $line ))) {
                            $this->appendLog(
                                message:    $line,
                                lineNumber: $lineNumber,
                                maxLine:    $maxLine
                            );

                            $line = '';
                        } else {
                            $line .= $result[ $lastLineIndex ];
                        }

                        $lastLineIndex++;
                    }
                }
            } else if (
                strpos($result, 'ok')     !== false // command finished
                ||
                strpos($result, 'paused') !== false // paused for user
                ||
                (
                    strpos($result, 'echo')   !== false // command progress
                    &&
                    strpos($result, 'busy')   === false // busy running command
                )
                &&
                $spentBlankingMs >= self::CONSOLE_EXPECTED_RESPONSE_RATE_MILLIS
            ) {
                if ($this->log) $this->log->debug("End of output detected: ({$spentBlankingMs} ms without data).");

                break;
            }

            if ($timeout && (time() - $sTime >= $timeout)) break;

            // usleep(1);
        }

        $spentSecs = time() - $sTime;

        if ($timeout && $spentSecs >= $timeout) {
            throw new TimedOutException("timed out while waiting for a newline after {$spentSecs} seconds were spent trying to get a response.");
        }

        if ($this->log) {
            $this->log->debug( __METHOD__ . ': ' . json_encode($result) );

            $this->log->debug('RECV: ' . $result);
        }

        $terminalMessage = trim(
            substr(
                string: $result,
                offset: $lastLineIndex
            )
        );

        if ($this->terminalAutoAppend) {
            $this->appendLog( $terminalMessage );
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

        if ($command) {
            $this->sendCommand( $command, $lineNumber, $maxLine );
        }

        $result = $this->readUntilBlank(
            timeout:    $timeout,
            lineNumber: $lineNumber,
            maxLine:    $maxLine
        );

        $this->lockCache->forget( $this->lockKey );

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