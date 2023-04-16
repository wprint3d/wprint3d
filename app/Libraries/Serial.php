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

use Illuminate\Support\Str;

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

    const TERMINAL_PATH   = '/dev';
    const TERMINAL_PREFIX = 'tty';

    const CACHE_LOCK_SUFFIX = '_nodeLock';
    const CACHE_LOCK_TTL    = 60; // seconds

    const CACHE_REFRESH_RATE_MICROS = 500; // microseconds

    const CONSOLE_EXPECTED_RESPONSE_RATE_MILLIS = 8; // ms

    /**
     * __construct
     *
     * @param  string   $fileName  - the node file name (as in, if you're looking for 'ttyUSB0', you'd write 'USB0')
     * @param  int      $baudRate  - the rate (in bits per second) on which data will be processed
     * @param  ?int     $timeout   - the maximum amount of time that can be spent on a read
     * @param  ?string  $printerId - the ObjectId of the printer related to this transaction
     * 
     * @throws InitializationException
     * 
     * @return void
     */
    public function __construct(string $fileName, int $baudRate, ?int $timeout = null, ?string $printerId = null) {
        $this->fileName  = $fileName;
        $this->baudRate  = $baudRate;
        $this->printerId = $printerId;

        $this->lockCache = Cache::store();

        $this->lockKey   = $this->fileName . self::CACHE_LOCK_SUFFIX;

        if (Configuration::get('debugSerial', env('SERIAL_DEBUG', false))) {
            $this->log = Log::channel('serial');
        }

        $this->terminalMaxLines = Configuration::get('terminalMaxLines', env('TERMINAL_MAX_LINES'));

        $this->configure();

        $this->timeout = $timeout;

        if ($this->timeout === null) {
            $this->timeout = Configuration::get('commandTimeoutSecs');
        }

        if (!$this->fd) {
            throw new InitializationException('Failed to open connection.');
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
        if ($this->printerId) {
            $terminal = Printer::getConsoleOf( $this->printerId );

            if (!$terminal) {
                $terminal = '';
            }

            $dateString = nowHuman();

            $line = $dateString . ': ' . $message;

            try {
                PrinterTerminalUpdated::dispatch(
                    $this->printerId, // printerId
                    $dateString,      // dateString
                    $message,         // command
                    $lineNumber,      // line
                    $maxLine          // maxLine
                );
            } catch (Exception $exception) {
                if ($this->log) {
                    $this->log->warning(
                        __METHOD__ . ': PrinterTerminalUpdated: event dispatch failure: ' . $exception->getMessage() . PHP_EOL .
                        $exception->getTraceAsString()
                    );
                }
            }

            $terminal .= trim($line) . PHP_EOL;

            if ($this->terminalMaxLines) {
                while (Str::substrCount($terminal, PHP_EOL) > $this->terminalMaxLines) {
                    $terminal = Str::substr(
                        string: $terminal,
                        start:  strpos($terminal, PHP_EOL) + 1
                    );
                }
            }

            Printer::setConsoleOf( $this->printerId, $terminal );
        }
    }

    public function sendCommand(string $command, ?int $lineNumber = null, ?int $maxLine = null) {
        $this->lockCache->put(
            key:    $this->lockKey,
            value:  true,
            ttl:    self::CACHE_LOCK_TTL
        );

        if ($this->log) {
            $this->log->debug('dio_write: ' . $command);
        }

        if ($this->printerId) {
            $this->appendLog(
                message:    ' > ' . $command . PHP_EOL,
                lineNumber: $lineNumber,
                maxLine:    $maxLine
            );
        }

        dio_write($this->fd, $command . PHP_EOL);

        if ($this->log) {
            $this->log->debug('SENT');
        }

        $this->lockCache->forget( $this->lockKey );
    }
    
    /**
     * readUntilBlank
     *
     * @param  ?int $timeout - custom timeout
     * 
     * @return string
     */
    public function readUntilBlank(?int $timeout = null, ?int $lineNumber = null, ?int $maxLine = null) : string {
        $this->lockCache->put(
            key:    $this->lockKey,
            value:  true,
            ttl:    self::CACHE_LOCK_TTL
        );

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

            if ($read) {
                if ($this->log) {
                    $this->log->debug('dio_read: ' . $read);
                }

                $result .= $read;

                $sTime     = time();
                $blankTime = millis();

                if ($this->printerId) {
                    while (isset($result[ $lastLineIndex ])) {
                        if ($result[ $lastLineIndex ] == PHP_EOL && trim( $line )) {
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
                (
                    strpos($result, 'ok')     !== false // command finished
                    ||
                    strpos($result, 'paused') !== false // paused for user
                    ||
                    (
                        strpos($result, 'echo')   !== false // paused for user
                        &&
                        strpos($result, 'busy')   === false // paused for user
                    )
                )
                &&
                millis() - $blankTime >= self::CONSOLE_EXPECTED_RESPONSE_RATE_MILLIS
            ) {
                if ($this->log) $this->log->debug('End of output detected: (' . (millis() - $blankTime) . 'ms without data).');

                break;
            }

            if ($timeout && (time() - $sTime >= $timeout)) break;

            // usleep(1);
        }

        $this->lockCache->forget( $this->lockKey );

        if ($timeout && time() - $sTime >= $timeout) {
            throw new TimedOutException('timed out while waiting for a newline after ' . (time() - $sTime) . ' seconds were spent trying to get a response.');
        }

        if ($this->log) {
            $this->log->debug( __METHOD__ . ': ' . json_encode($result) );

            $this->log->debug('RECV: ' . $result);
        }

        // Push the remaining lines to the web console
        while (isset($result[ $lastLineIndex ])) {
            if ($result[ $lastLineIndex ] == PHP_EOL && trim( $line )) {
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
        
        foreach (
            explode(
                separator: PHP_EOL,
                string:    substr(
                    string: $result,
                    offset: $lastLineIndex
                )
            )
            as $line
        ) {
            if (trim( $line )) {
                $this->appendLog(
                    message:    $line,
                    lineNumber: $lineNumber,
                    maxLine:    $maxLine
                );
            }
        }

        return trim($result);
    }

    public function query(?string $command = null, ?int $lineNumber = null, ?int $maxLine = null, ?int $timeout = null) : string {
        if ($command) {
            $this->sendCommand( $command, $lineNumber, $maxLine );
        }

        return $this->readUntilBlank(
            timeout:    $timeout,
            lineNumber: $lineNumber,
            maxLine:    $maxLine
        );
    }

    public static function nodeExists(string $fileName) : bool {
        return file_exists(
            self::TERMINAL_PATH . '/' . self::TERMINAL_PREFIX . $fileName
        );
    }

}

?>