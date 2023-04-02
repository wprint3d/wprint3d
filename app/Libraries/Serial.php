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

    const CONSOLE_READ_BUFFER_SIZE_BYTES        = 4096; // bytes
    const CONSOLE_EXPECTED_RESPONSE_RATE_MILLIS = 32;   // ms

    const CACHE_LOCK_SUFFIX = '_nodeLock';
    const CACHE_LOCK_TTL    = 60; // seconds

    /**
     * __construct
     *
     * @param  string $fileName
     * @param  int    $baudRate
     * @param  ?int   $timeout
     * 
     * @throws InitializationException
     * 
     * @return void
     */
    public function __construct(string $fileName, int $baudRate, ?int $timeout = 10, ?string $printerId = null) {
        $this->fileName   = $fileName;
        $this->baudRate   = $baudRate;
        $this->printerId  = $printerId;

        $this->lockCache  = Cache::store();
        $this->lockKey    = $this->fileName . self::CACHE_LOCK_SUFFIX;

        if (Configuration::get('debugSerial', env('SERIAL_DEBUG', false))) {
            $this->log = Log::channel('serial');
        }

        $this->terminalMaxLines = Configuration::get('terminalMaxLines', env('TERMINAL_MAX_LINES'));

        $this->configure();

        if ($timeout) {
            $this->timeout = $timeout;
        }

        if (!$this->fd) {
            throw new InitializationException('Failed to open connection.');
        }
    }

    private function configure() {
        while ($this->lockCache->get($this->lockKey, false)) {} // block

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

            // Log::info('PTU: ' . $this->printerId);

            PrinterTerminalUpdated::dispatch(
                $this->printerId, // printerId
                $dateString,      // dateString
                $message,         // command
                $lineNumber,      // line
                $maxLine          // maxLine
            );

            $terminal .= $line;

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
        $this->configure();

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
        $this->configure();

        $this->lockCache->put(
            key:    $this->lockKey,
            value:  true,
            ttl:    self::CACHE_LOCK_TTL
        );

        if (!$timeout) {
            $timeout = $this->timeout;
        }

        $result = '';

        if ($timeout) {
            $sTime = time();
        }

        $blankTime = millis();

        while (true) {
            $read = dio_read($this->fd, self::CONSOLE_READ_BUFFER_SIZE_BYTES);

            $millis = millis();

            if ($read) {
                if ($this->log) $this->log->debug('dio_read: ' . $read);

                $result .= $read;

                $blankTime = $millis;
            } else if ($result && $millis - $blankTime >= self::CONSOLE_EXPECTED_RESPONSE_RATE_MILLIS) {
                if ($this->log) $this->log->debug('End of output detected: (' . (millis() - $blankTime) . 'ms without data).');

                break;
            }

            if ($timeout && (time() - $sTime >= $timeout)) break;
        }

        $this->lockCache->forget( $this->lockKey );

        if ($timeout && time() - $sTime >= $timeout) {
            throw new TimedOutException('timed out while waiting for a newline after ' . (time() - $sTime) . ' seconds were spent trying to get a response.');
        }

        if ($this->log) {
            $this->log->debug( __METHOD__ . ': ' . json_encode($result) );
        }

        $this->appendLog(
            message:    $result,
            lineNumber: $lineNumber,
            maxLine:    $maxLine
        );

        return trim($result);
    }

    public function query(string $command, ?int $lineNumber = null, ?int $maxLine = null) : string {
        $this->sendCommand( $command, $lineNumber, $maxLine );

        return $this->readUntilBlank();
    }

    public static function nodeExists(string $fileName) : bool {
        return file_exists(
            self::TERMINAL_PATH . '/' . self::TERMINAL_PREFIX . $fileName
        );
    }

}

?>