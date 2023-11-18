<?php

namespace App\Models;

use App\Enums\PauseReason;

use App\Events\CommandQueued;

use Illuminate\Database\Eloquent\Factories\HasFactory;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

use Jenssegers\Mongodb\Eloquent\Model;

use MongoDB\BSON\ObjectId;

use Exception;

class Printer extends Model
{
    use HasFactory;

    const CACHE_TTL = 600;

    const CACHE_STATISTICS_SUFFIX        = '_statistics';
    const CACHE_LAST_ERROR_SUFFIX        = '_lastError';
    const CACHE_LAST_COMMAND_SUFFIX      = '_lastCommand';
    const CACHE_RUN_STATUS_SUFFIX        = '_runStatus';
    const CACHE_PAUSE_REASON_SUFFIX      = '_pauseReason';
    const CACHE_CONSOLE_SUFFIX           = '_console';
    const CACHE_QUEUED_COMMANDS_SUFFIX   = '_queuedCommands';
    const CACHE_ACTIVE_USER_ID_SUFFIX    = '_activeUserId';
    const CACHE_CURRENT_LINE_SUFFIX      = '_currentLine';
    const CACHE_MAX_LINE_SUFFIX          = '_maxLine';
    const CACHE_ABSOLUTE_POSITION_SUFFIX = '_absolutePosition';
    const CACHE_LAST_SEEN_SUFFIX         = '_lastSeen';

    const MARLIN_TEMPERATURE_INDICATOR      = 'T:';
    const MARLIN_MULTI_TEMPERATURE_TEMPLATE = 'T%s:';

    protected $fillable = [
        'node',
        'baudRate',
        'lastSeen',
        'queuedCommands',
        'cameras',
        'connected',
        'machine',
        'machine.firmwareName',
        'machine.sourceCodeUrl',
        'machine.protocolVersion',
        'machine.machineType',
        'machine.extruderCount',
        'machine.axisCount',
        'machine.uuid',
        'machine.capabilities.serialXonXoff',
        'machine.capabilities.binaryFileTransfer',
        'machine.capabilities.eeprom',
        'machine.capabilities.volumetric',
        'machine.capabilities.autoreportPos',
        'machine.capabilities.autoreportTemp',
        'machine.capabilities.progress',
        'machine.capabilities.printJob',
        'machine.capabilities.autolevel',
        'machine.capabilities.runout',
        'machine.capabilities.zProbe',
        'machine.capabilities.levelingData',
        'machine.capabilities.buildPercent',
        'machine.capabilities.softwarePower',
        'machine.capabilities.toggleLights',
        'machine.capabilities.caseLightBrightness',
        'machine.capabilities.emergencyParser',
        'machine.capabilities.hostActionCommands',
        'machine.capabilities.promptSupport',
        'machine.capabilities.sdcard',
        'machine.capabilities.repeat',
        'machine.capabilities.sdWrite',
        'machine.capabilities.autoreportSdStatus',
        'machine.capabilities.longFilename',
        'machine.capabilities.lfnWrite',
        'machine.capabilities.customFirmwareUpload',
        'machine.capabilities.extendedM20',
        'machine.capabilities.thermalProtection',
        'machine.capabilities.motionModes',
        'machine.capabilities.arcs',
        'machine.capabilities.babystepping',
        'machine.capabilities.chamberTemperature',
        'machine.capabilities.coolerTemperature',
        'machine.capabilities.meatpack',
        'machine.capabilities.configExport',
        'recordableCameras',
        'settings',
        'settings.autoScroll',
        'settings.showSensors',
        'settings.showInputCommands',
        'settings.showExtrusion',
        'settings.showTravel',
        'hasActiveJob',
        'lastJobHasFailed',
        'activeFile',
        'lastLine',
    ];
    
    /**
     * supports
     * 
     * Check whether a printer supports a feature (reported by the firmware).
     *
     * @param  mixed $capability
     * @return bool
     */
    public function supports(string $capability): bool {
        return
            isset( $this->machine['capabilities'] )
            &&
            isset( $this->machine['capabilities'][ $capability ] )
            &&
            $this->machine['capabilities'][ $capability ];
    }

    public function getCameras() {
        if (!$this->cameras) {
            return collect(); // empty 
        }

        return Camera::whereIn('_id',
            array_map(
                callback: fn($item) => new ObjectId($item),
                array:    $this->cameras
            )
        )->cursor();
    }

    public function getRecordableCameras() {
        if (!$this->recordableCameras) {
            return collect(); // empty 
        }

        return Camera::whereIn('_id',
            array_map(
                callback: fn($item) => new ObjectId($item),
                array:    $this->recordableCameras
            )
        )->whereIn('_id',
            array_map(
                callback: fn($item) => new ObjectId($item),
                array:    $this->cameras
            )
        )->cursor();
    }

    /**
     * getStatistics
     * 
     * @return array
     */
    public function getStatistics() : array {
        return Cache::get(
            key:     $this->_id . self::CACHE_STATISTICS_SUFFIX,
            default: []
        );
    }

    /**
     * getStatisticsOf
     * 
     * @return array
     */
    public static function getStatisticsOf(string $printerId) : array {
        return Cache::get(
            key:     $printerId . self::CACHE_STATISTICS_SUFFIX,
            default: []
        );
    }

    private static function tryStatisticsUpdate(string $printerId, string $lines, int $extruderIndex) {
        $lines = Str::of( $lines )->explode(PHP_EOL)->toArray();

        $rawData = '';

        foreach ($lines as $line) {
            if (Str::contains($line, self::MARLIN_TEMPERATURE_INDICATOR)) {
                $rawData = $line;

                break;
            }
        }

        if (!$rawData) {
            Log::warning( __METHOD__ . ': failed to set temperature: no valid strings found. Input was: ' . json_encode( Arr::join($lines, PHP_EOL) ) );

            return false;
        }

        try {
            $temperatures = trim( str_replace('ok', '', $rawData) );

            $temperatures = preg_replace(
                pattern:     '/' . sprintf(self::MARLIN_MULTI_TEMPERATURE_TEMPLATE, '0') . '.*/',
                replacement: '',
                subject:     $temperatures
            );

            $temperatures = preg_split('/.:/', $temperatures, 3, PREG_SPLIT_NO_EMPTY); // split in chunks of temperature type (first goes hotend, then, bed)

            if (!isset( $temperatures[0] )) {
                Log::warning( __METHOD__ . ': failed to set temperature: unexpected input. Input was: ' . $rawData );

                return false;
            }

            $hotend = explode('/', $temperatures[0]);

            $statistics = self::getStatisticsOf( $printerId );
            $statistics['extruders'][ $extruderIndex ] = [
                'temperature'   => doubleval( trim($hotend[0]) )
            ];

            if (isset( $hotend[1] )) {
                $statistics['extruders'][ $extruderIndex ]['target'] = doubleval( trim($hotend[1]) );
            }

            if ($extruderIndex == 0 && isset( $temperatures[1] )) {
                $bed = explode('/', $temperatures[1]);

                $statistics['bed'] = [
                    'temperature'   => doubleval( trim($bed[0]) ),
                    'target'        => doubleval( trim($bed[1]) )
                ];
            }

            Cache::put(
                key:    $printerId . self::CACHE_STATISTICS_SUFFIX,
                value:  $statistics,
                ttl:    self::CACHE_TTL
            );
        } catch (Exception $exception) {
            Log::warning( __METHOD__ . ': failed to set statistics: ' . $exception->getMessage() . '. Input was: ' . $rawData );

            return false;
        }

        return true;
    }

    /**
     * setStatistics
     *
     * @param  string $rawData (return status of M105)
     * @param  int    $extruderIndex
     *
     * @return bool - whether it's been saved successfully
     */
    public function setStatistics(string $lines, int $extruderIndex) : bool {
        return self::tryStatisticsUpdate(
            printerId:     $this->_id,
            lines:         $lines,
            extruderIndex: $extruderIndex
        );
    }

    /**
     * setStatisticsOf
     *
     * @param  string $rawData (return status of M105)
     * @param  int    $extruderIndex
     *
     * @return bool - whether it's been saved successfully
     */
    public static function setStatisticsOf(string $printerId, string $lines, int $extruderIndex) : bool {
        return self::tryStatisticsUpdate(
            printerId:     $printerId,
            lines:         $lines,
            extruderIndex: $extruderIndex
        );
    }
    
    /**
     * getLastError
     *
     * @return ?string
     */
    public function getLastError() : ?string {
        return Cache::get(
            $this->_id . self::CACHE_LAST_ERROR_SUFFIX
        );
    }
    
    /**
     * setLastError
     *
     * @param  mixed $message
     * 
     * @return bool
     */
    public function setLastError(string $message) : bool {
        return Cache::put(
            key:    $this->_id . self::CACHE_LAST_ERROR_SUFFIX,
            value:  $message,
            ttl:    self::CACHE_TTL
        );
    }
    
    /**
     * getLastCommand
     *
     * @return ?string
     */
    public function getLastCommand() : ?string {
        return Cache::get( $this->_id . self::CACHE_LAST_COMMAND_SUFFIX );
    }
    
    /**
     * setLastCommand
     *
     * @param  string $command
     * 
     * @return bool
     */
    public function setLastCommand(?string $command) : bool {
        return Cache::put(
            key:    $this->_id . self::CACHE_LAST_COMMAND_SUFFIX,
            value:  $command,
            ttl:    self::CACHE_TTL
        );
    }

    /**
     * getPauseReason
     *
     * @return ?int
     */
    public function getPauseReason() : ?int {
        return Cache::get( $this->_id . self::CACHE_PAUSE_REASON_SUFFIX );
    }

    /**
     * pause
     *
     * @return bool
     */
    public function pause(int $reason = PauseReason::MANUAL) : bool {
        Cache::put(
            key:     $this->_id . self::CACHE_PAUSE_REASON_SUFFIX,
            value:   $reason
        );

        return Cache::put(
            key:     $this->_id . self::CACHE_RUN_STATUS_SUFFIX,
            value:   false
        );
    }
    
    /**
     * resume
     *
     * @return bool
     */
    public function resume() : bool {
        Cache::forget( $this->_id . self::CACHE_PAUSE_REASON_SUFFIX );

        return Cache::put(
            key:     $this->_id . self::CACHE_RUN_STATUS_SUFFIX,
            value:   true,
            ttl:     self::CACHE_TTL
        );
    }
    
    /**
     * isRunning
     *
     * @return bool
     */
    public function isRunning() : bool {
        return Cache::get(
            key:     $this->_id . self::CACHE_RUN_STATUS_SUFFIX,
            default: true
        );
    }

    /**
     * getRunningStatusOf
     *
     * @return bool
     */
    public static function getRunningStatusOf(string $printerId) : bool {
        return Cache::get(
            key:     $printerId . self::CACHE_RUN_STATUS_SUFFIX,
            default: true
        );
    }

    private function getConsoleKey() : string {
        return $this->_id . self::CACHE_CONSOLE_SUFFIX;
    }

    public function getConsole() {
        return Cache::get(
            key:     $this->getConsoleKey(),
            default: ''
        );
    }

    public function setConsole(string $terminal) {
        return Cache::put(
            key:     self::getConsoleKey(),
            value:   $terminal,
            ttl:     self::CACHE_TTL
        );
    }

    /**
     * getResetQueuedCommands
     * 
     * Get and reset the printer's manually queued commands (typed in from the
     * web frontend).
     *
     * @return bool
     */
    public function getResetQueuedCommands() : array {
        $result = Cache::get(
            key:     $this->_id . self::CACHE_QUEUED_COMMANDS_SUFFIX,
            default: []
        );

        Cache::forget( $this->_id . self::CACHE_QUEUED_COMMANDS_SUFFIX );

        return $result;
    }
    
    /**
     * queueCommand
     * 
     * Add a command to the manually input commands queue.
     *
     * @param  string $command
     * 
     * @return bool
     */
    public function queueCommand(string $command) : bool {
        $result = Cache::get(
            key:     $this->_id . self::CACHE_QUEUED_COMMANDS_SUFFIX,
            default: []
        );

        $result[] = $command;

        $queued = Cache::put(
            key:     $this->_id . self::CACHE_QUEUED_COMMANDS_SUFFIX,
            value:   $result,
            ttl:     self::CACHE_TTL
        );

        if ($queued) {
            CommandQueued::dispatch( $this->_id );
        }

        return $queued;
    }

    public function getCurrentLine() : int {
        return Cache::get(
            key:     $this->_id . self::CACHE_CURRENT_LINE_SUFFIX,
            default: 0
        );
    }

    public function setCurrentLine(int $line) : bool {
        return Cache::put(
            key:     $this->_id . self::CACHE_CURRENT_LINE_SUFFIX,
            value:   $line
        );
    }

    public function getMaxLine() : int {
        return Cache::get(
            key:     $this->_id . self::CACHE_MAX_LINE_SUFFIX,
            default: 0
        );
    }

    public function setMaxLine(int $line) : bool {
        return Cache::put(
            key:     $this->_id . self::CACHE_MAX_LINE_SUFFIX,
            value:   $line
        );
    }

    public function incrementCurrentLine() : int {
        return Cache::increment( $this->_id . self::CACHE_CURRENT_LINE_SUFFIX );
    }

    public function getAbsolutePosition() : array {
        return Cache::get(
            key:     $this->_id . self::CACHE_ABSOLUTE_POSITION_SUFFIX,
            default: [
                'x' => null,
                'y' => null,
                'z' => null
            ]
        );
    }

    public function setAbsolutePosition(?float $x, ?float $y, ?float $z, ?float $e) : bool {
        return Cache::put(
            key:     $this->_id . self::CACHE_ABSOLUTE_POSITION_SUFFIX,
            value:   [
                'x' => $x,
                'y' => $y,
                'z' => $z,
                'e' => $e
            ]
        );
    }

    private static function getConsoleKeyOf(string $printerId) : string {
        return $printerId . self::CACHE_CONSOLE_SUFFIX;
    }

    public static function getConsoleOf(string $printerId) {
        return Cache::get(
            key:     self::getConsoleKeyOf( $printerId ),
            default: ''
        );
    }

    public static function setConsoleOf(string $printerId, string $terminal) {
        return Cache::put(
            key:     self::getConsoleKeyOf( $printerId ),
            value:   $terminal,
            ttl:     self::CACHE_TTL
        );
    }

    public function getLastSeen() {
        return Cache::get( $this->_id . self::CACHE_LAST_SEEN_SUFFIX );
    }

    public static function getLastSeenOf(string $printerId) {
        return Cache::get( $printerId . self::CACHE_LAST_SEEN_SUFFIX );
    }

    public function updateLastSeen(): int {
        $newLastSeen = time();

        Cache::put(
            key:     $this->_id . self::CACHE_LAST_SEEN_SUFFIX, 
            value:   $newLastSeen,
            ttl:     self::CACHE_TTL
        );

        return $newLastSeen;
    }

    public static function updateLastSeenOf(string $printerId): int {
        $newLastSeen = time();

        Cache::put(
            key:     $printerId . self::CACHE_LAST_SEEN_SUFFIX, 
            value:   $newLastSeen,
            ttl:     self::CACHE_TTL
        );

        return $newLastSeen;
    }
}
