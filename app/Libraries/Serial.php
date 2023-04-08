<?php

namespace App\Libraries;

use App\Enums\SerialForkType;
use App\Events\PrinterTerminalUpdated;

use App\Models\Configuration;
use App\Models\Printer;

use App\Exceptions\InitializationException;
use App\Exceptions\TimedOutException;

use Illuminate\Console\Command;

use Illuminate\Log\Logger;

use Illuminate\Support\Facades\Log;

use Illuminate\Support\Str;

use Exception;
use Redis;

class Serial {

    private $fd;

    private Redis $cache;

    private string      $uid;
    private string      $fileName;
    private int         $baudRate;
    private int         $terminalMaxLines;

    private ?string $printerId  = null;
    private ?int    $timeout    = null;

    private array $children;

    private ?Logger $log = null;

    const UID_PREFIX = 'ser';

    const TERMINAL_PATH   = '/dev';
    const TERMINAL_PREFIX = 'tty';

    const CONSOLE_READ_BUFFER_SIZE_BYTES        = 2048; // bytes
    const CONSOLE_EXPECTED_RESPONSE_RATE_MILLIS = 16;   // ms

    const CACHE_RESPONSE_LINE_SUFFIX    = '_response';
    const CACHE_LAST_ERROR_LINE_SUFFIX  = '_lastError';
    const CACHE_IS_DESTRUCTING_SUFFIX   = '_isDestructing';
    const CACHE_DO_READ_SUFFIX          = '_doRead';
    const CACHE_NEXT_COMMAND_SUFFIX     = '_nextCommand';
    const CACHE_NEXT_TIMEOUT_SUFFIX     = '_nextTimeout';
    const CACHE_NEXT_LINE_NUMBER_SUFFIX = '_nextLineNumber';
    const CACHE_NEXT_MAX_LINE_SUFFIX    = '_nextMaxLine';

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
    public function __construct(string $fileName, int $baudRate, ?int $timeout = 10, ?string $printerId = null) {
        $this->uid        = uniqid(self::UID_PREFIX, true);
        $this->cache      = $this->store();
        $this->fileName   = $fileName;
        $this->baudRate   = $baudRate;
        $this->printerId  = $printerId;

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

        $this->forgetCache($this->cache, self::CACHE_IS_DESTRUCTING_SUFFIX );

        $pid = -1;

        $this->children = [];

        foreach (SerialForkType::asArray() as $thread) {
            $pid = pcntl_fork();

            if ($pid === -1) { // fork error
                throw new InitializationException('failed to fork thread');
            } else if ($pid === 0) { // child
                $cache = $this->store();

                while (true) {
                    try {
                        $this->getCache( $cache, self::CACHE_IS_DESTRUCTING_SUFFIX );

                        switch ($thread) {
                            case SerialForkType::READER:
                                if ($this->getCache( $cache, self::CACHE_DO_READ_SUFFIX )) {
                                    $this->setCache(
                                        cache:  $cache,
                                        suffix: self::CACHE_RESPONSE_LINE_SUFFIX,
                                        value:  $this->readUntilBlank(
                                            timeout:    $this->getCache( $cache, self::CACHE_NEXT_TIMEOUT_SUFFIX     ),
                                            lineNumber: $this->getCache( $cache, self::CACHE_NEXT_LINE_NUMBER_SUFFIX ),
                                            maxLine:    $this->getCache( $cache, self::CACHE_NEXT_MAX_LINE_SUFFIX    ),
                                        )
                                    );

                                    usleep( (self::CONSOLE_EXPECTED_RESPONSE_RATE_MILLIS / 4) * 1000 );

                                    $this->forgetCache( $cache, self::CACHE_DO_READ_SUFFIX );
                                }
                            break;
                            case SerialForkType::WRITER:
                                if ($this->getCache( $cache, self::CACHE_NEXT_COMMAND_SUFFIX )) {
                                    $this->sendCommand(
                                        command:    $this->getCache( $cache, self::CACHE_NEXT_COMMAND_SUFFIX     ),
                                        lineNumber: $this->getCache( $cache, self::CACHE_NEXT_LINE_NUMBER_SUFFIX ),
                                        maxLine:    $this->getCache( $cache, self::CACHE_NEXT_MAX_LINE_SUFFIX    )
                                    );

                                    $this->forgetCache( $cache, self::CACHE_NEXT_COMMAND_SUFFIX );
                                }
                            break;
                            default:
                                $this->log->warning( __METHOD__ . ": we shouldn\'t be here! Invalid thread type, got: {$thread}" );
                        }
                    } catch (Exception $exception) { // catch all exceptions, we'll handle them later
                        $this->forgetCache( $cache, self::CACHE_DO_READ_SUFFIX      );
                        $this->forgetCache( $cache, self::CACHE_NEXT_COMMAND_SUFFIX );

                        $this->setCache(
                            cache:  $cache,
                            suffix: self::CACHE_LAST_ERROR_LINE_SUFFIX,
                            value:  serialize([
                                'class'     => get_class($exception),
                                'message'   =>
                                    $exception->getMessage() . PHP_EOL .
                                    $exception->getTraceAsString()
                            ])
                        );
                    }

                    if ($this->getCache( $cache, self::CACHE_IS_DESTRUCTING_SUFFIX )) {
                        exit( Command::SUCCESS );
                    }

                    usleep( (self::CONSOLE_EXPECTED_RESPONSE_RATE_MILLIS / 8) * 1000 );
                }

                exit( Command::SUCCESS );
            } else { // parent
                $this->children[] = $pid;
            }
        }
    }

    public function __destruct()
    {
        $cache = $this->store();

        $this->setCache(
            cache:  $cache,
            suffix: self::CACHE_IS_DESTRUCTING_SUFFIX,
            value:  true
        );

        // Waiting for children to finish
        foreach ($this->children as $pid) {
            pcntl_waitpid($pid, $status);
        }
    }

    private function store(): Redis {
        $redis = new Redis();
        $redis->connect(
            host: env('REDIS_HOST'),
            port: env('REDIS_PORT')
        );

        return $redis;
    }

    private function setCache(Redis &$cache, string $suffix, mixed $value): bool {
        return $cache->set(
            key:    config('cache.prefix') . $this->uid . '_' . $this->fileName . $suffix,
            value:  $value
        );
    }

    private function getCache(Redis &$cache, string $suffix): mixed {
        return $cache->get(
            config('cache.prefix') . $this->uid . '_' . $this->fileName . $suffix
        );
    }

    private function forgetCache(Redis &$cache, string $suffix): int {
        return $cache->del(
            config('cache.prefix') . $this->uid . '_' . $this->fileName . $suffix
        );
    }

    private function configure() {
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
    }
    
    /**
     * readUntilBlank
     *
     * @param  ?int $timeout    - custom timeout
     * @param  ?int $lineNumber - the current line number
     * @param  ?int $maxLine    - the maximum line number
     * 
     * @throws TimedOutException
     * 
     * @return string
     */
    public function readUntilBlank(?int $timeout = null, ?int $lineNumber = null, ?int $maxLine = null) : string {
        $this->configure();

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

        if ($timeout && time() - $sTime >= $timeout) {
            throw new TimedOutException('timed out while waiting for a newline after ' . (time() - $sTime) . ' seconds were spent trying to get a response.');
        }

        if ($this->log) {
            $this->log->debug( __METHOD__ . ': ' . json_encode($result) );

            $this->log->debug('RECV: ' . $result);
        }

        $this->appendLog(
            message:    $result,
            lineNumber: $lineNumber,
            maxLine:    $maxLine
        );

        return trim($result);
    }

    public function query(?string $command = null, ?int $lineNumber = null, ?int $maxLine = null, ?int $timeout = null): string {
        if ($lineNumber !== null) {
            $this->setCache(
                cache:  $this->cache,
                suffix: self::CACHE_NEXT_LINE_NUMBER_SUFFIX,
                value:  $lineNumber
            );
        }

        if ($maxLine !== null) {
            $this->setCache(
                cache:  $this->cache,
                suffix: self::CACHE_NEXT_MAX_LINE_SUFFIX,
                value:  $maxLine
            );
        }

        if ($timeout !== null) {
            $this->setCache(
                cache:  $this->cache,
                suffix: self::CACHE_NEXT_TIMEOUT_SUFFIX,
                value:  $timeout
            );
        }

        $this->setCache(
            cache:  $this->cache,
            suffix: self::CACHE_DO_READ_SUFFIX,
            value:  true
        );

        if ($command) {
            $this->setCache(
                cache:  $this->cache,
                suffix: self::CACHE_NEXT_COMMAND_SUFFIX,
                value:  $command
            );
        }

        while ($this->getCache( $this->cache, self::CACHE_DO_READ_SUFFIX )) {
            usleep( (self::CONSOLE_EXPECTED_RESPONSE_RATE_MILLIS / 2) * 1000 );
        }

        $response  = $this->getCache( $this->cache, self::CACHE_RESPONSE_LINE_SUFFIX   );
        $lastError = $this->getCache( $this->cache, self::CACHE_LAST_ERROR_LINE_SUFFIX );

        if ($lastError) {
            $lastError = unserialize( $lastError );
        }

        $this->forgetCache( $this->cache, self::CACHE_RESPONSE_LINE_SUFFIX   );
        $this->forgetCache( $this->cache, self::CACHE_LAST_ERROR_LINE_SUFFIX );

        if ($lastError) {
            throw new $lastError['class']( $lastError['message'] );
        }

        return $response;
    }

    public static function nodeExists(string $fileName) : bool {
        return file_exists(
            self::TERMINAL_PATH . '/' . self::TERMINAL_PREFIX . $fileName
        );
    }

}

?>