<?php

namespace App\Console\Commands;

use App\Events\PrintersMapInProgress;
use App\Events\PrintersMapUpdated;
use App\Exceptions\TimedOutException;
use App\Libraries\Serial;
use App\Models\Configuration;
use App\Models\Printer;
use App\Plugins\PluginHookCompiler;
use App\Support\FakeSerial\FakeSerialManager;
use App\Support\SerialMapperDebouncer;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Log\Logger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use Throwable;

class MapSerialPrinters extends Command
{
    private const MAPPER_BUSY_TTL_SECS = 30;

    private const MAPPER_BUSY_HEARTBEAT_SECS = 10;

    private const MAPPER_LOCK_TTL_SECS = 300;

    private const MAPPER_LOCK_WAIT_SECS = 300;

    private function canonicalMachineUuid(array $machine, string $device, bool $isFakeSerial): string
    {
        if ($isFakeSerial) {
            return $machine['uuid'].'/'.hash(
                algo: 'crc32',
                data: $device
            );
        }

        return $machine['uuid'].'/'.hash(
            algo: 'crc32',
            data: json_encode($machine)
        );
    }

    private function fakeSerialUuidRegex(string $baseUuid): Regex
    {
        return new Regex('^'.preg_quote($baseUuid, '/').'(/.*)?$', 'i');
    }

    private function findExistingPrinter(array $machine, string $device, bool $isFakeSerial, ?Printer $printerAtNode = null): ?Printer
    {
        if (
            $printerAtNode
            &&
            Str::before((string) ($printerAtNode->machine['uuid'] ?? ''), '/') === Str::before($machine['uuid'], '/')
        ) {
            return $printerAtNode;
        }

        $printer = Printer::where('machine.uuid', $machine['uuid'])->first();

        if ($printer || ! $isFakeSerial) {
            return $printer;
        }

        return Printer::where('machine.connectionType', 'fakeSerial')
            ->whereRaw([
                'machine.uuid' => $this->fakeSerialUuidRegex(Str::before($machine['uuid'], '/')),
            ])
            ->orderBy('created_at')
            ->first();
    }

    private function disconnectDuplicateFakePrinters(Printer $printer, string $baseUuid): void
    {
        foreach (
            Printer::where('machine.connectionType', 'fakeSerial')
                ->whereRaw([
                    'machine.uuid' => $this->fakeSerialUuidRegex($baseUuid),
                    '_id' => ['$ne' => new ObjectId($printer->_id)],
                ])
                ->cursor() as $duplicatePrinter
        ) {
            $duplicatePrinter->delete();
        }
    }

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'map:serial-printers
                            { node? : The target device node. i.e.: USB0, ACM0, etc. }
                            { --debounce=0 : Wait for this many quiet seconds and discard superseded invocations. }';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-map serial printers to the caching database.';

    /**
     * parseFirmwareInformation
     *
     * Parse the output of M115 and return the firmware information (such as
     * version, machine type, UUID, etc.) and its capabilities (whether it has
     * a z-probe, whether it supports arcs amongst other features).
     *
     *
     * @return array
     */
    private function parseFirmwareInformation(Logger $log, string $information)
    {
        $this->comment('   -> Parsing...');
        $log->debug('   -> Parsing...');

        $machine = [
            'capabilities' => [],
        ];

        foreach (Str::of($information)->explode(PHP_EOL) as $line => $info) {
            if ($line == 0) {
                $writingKey = true;

                $key = '';

                for ($index = 0; $index < strlen($info); $index++) {
                    if ($writingKey) {
                        if ($info[$index] == ' ') {
                            continue;
                        }

                        if ($info[$index] == ':') {
                            $writingKey = false;

                            $key = Str::of($key)->lower()->camel()->toString();
                        } else {
                            $key .= $info[$index];
                        }
                    } else {
                        if (! isset($machine[$key])) {
                            $machine[$key] = '';
                        }

                        $machine[$key] .= $info[$index];

                        if (
                            isset($info[$index + 1]) && $info[$index + 1] == ' '         // current + 1 must be a space
                            &&
                            isset($info[$index + 2]) && ctype_upper($info[$index + 2])  // current + 2 must be uppercase
                            &&
                            isset($info[$index + 3]) && ctype_upper($info[$index + 3])  // current + 3 must be uppercase
                        ) {
                            $key = '';

                            $writingKey = true;
                        }
                    }
                }
            } else {
                $info = Str::of($info);

                if ($info->startsWith('Cap:')) {
                    $keyValue = $info->replaceFirst('Cap:', '')->explode(':')->toArray();

                    try {
                        $key = Str::of($keyValue[0])->lower()->camel()->toString();
                        $value = (bool) ((int) $keyValue[1]) ?? false;

                        $machine['capabilities'][$key] = $value;

                        $this->comment("     -= F: {$key}: ".($value ? 'true' : 'false'));
                        $log->debug("     -= F: {$key}: ".($value ? 'true' : 'false'));
                    } catch (Exception $exception) {
                        $this->warn("     -> Parse error: {$exception->getMessage()}");
                        $log->warning("     -> Parse error: {$exception->getMessage()}");
                    }
                }
            }
        }

        if (! isset($machine['extruderCount'])) {
            $machine['extruderCount'] = 1;

            $log->warning('machine.extruderCount is not set!');
        }

        return $machine;
    }

    private function normalizeMachineForComparison(array $machine): array
    {
        foreach ($machine as $key => $value) {
            if (is_array($value)) {
                $machine[$key] = $this->normalizeMachineForComparison($value);
            }
        }

        if (Arr::isAssoc($machine)) {
            ksort($machine);
        }

        return $machine;
    }

    private function machinesMatch(Printer $printer, array $machine): bool
    {
        return $this->normalizeMachineForComparison($printer->machine ?? [])
            === $this->normalizeMachineForComparison($machine);
    }

    private function tryMapDeviceAtBaud(
        Logger $log,
        string $device,
        int $baudRate,
        int $negotiationTimeoutSecs,
        int $negotiationMaxRetries,
        array $serialPluginHooks,
        FakeSerialManager $fakeSerialManager,
        ?Printer $printerAtNode,
        callable $refreshMapperBusy,
        callable $waitWhileRefreshingMapperBusy,
        int &$retryCount,
        int &$changeCount
    ): bool {
        while (true) {
            $refreshMapperBusy();

            $response = '';
            $serial = null;

            try {
                try {
                    $serial = new Serial(
                        fileName: $device,
                        baudRate: $baudRate,
                        timeout: $negotiationTimeoutSecs,
                        pluginHooks: $serialPluginHooks
                    );
                    $serial->everyBusyMillis(
                        'mapperBusyHeartbeat',
                        self::MAPPER_BUSY_HEARTBEAT_SECS * 1000,
                        $refreshMapperBusy
                    );

                    $response = $serial->query('M105');
                } catch (TimedOutException $timedOutException) {
                    $errorMessage = "  - No response at {$baudRate} bps: {$timedOutException->getMessage()}";

                    $this->info($errorMessage);
                    $log->info($errorMessage);

                    return false;
                } catch (Throwable $exception) {
                    $errorMessage = "  - Negotiation error from serial port at node {$device} while trying with a baud rate of {$baudRate} bps: {$exception->getMessage()}";

                    $this->info($errorMessage);
                    $log->info($errorMessage);

                    return false;
                }

                $responseIsValidUtf8 = mb_check_encoding($response, 'UTF-8');

                if (! Str::contains($response, 'ok') || ! $responseIsValidUtf8) {
                    $responseForLog = ! $responseIsValidUtf8
                        ? 'base64:'.base64_encode($response)
                        : $response;
                    $warnMessage = "  - At {$baudRate}, this looks like a printer but it didn't expose a proper reply, let's wait a few seconds and try again. Got: {$responseForLog}";

                    $this->warn($warnMessage);
                    $log->warning($warnMessage);

                    $waitWhileRefreshingMapperBusy($negotiationTimeoutSecs);

                    if ($retryCount >= $negotiationMaxRetries) {
                        return false;
                    }

                    $retryCount++;

                    continue;
                }

                $refreshMapperBusy();

                try {
                    $machine = $this->parseFirmwareInformation(
                        log: $log,
                        information: $serial->query('M115')
                    );
                } catch (Throwable $exception) {
                    $infoMessage = "  -> Something went wrong while trying to gather information about the machine: {$exception->getMessage()}";

                    $this->info($infoMessage);
                    $log->info($infoMessage);

                    return false;
                }

                if (! isset($machine['uuid'])) {
                    $infoMessage = '  -> Invalid printer (no UUID available).';

                    $this->info($infoMessage);
                    $log->info($infoMessage);

                    return false;
                }

                $isFakeSerial = $fakeSerialManager->nodeExists($device);
                $baseUuid = $machine['uuid'];
                $machine['uuid'] = $this->canonicalMachineUuid($machine, $device, $isFakeSerial);
                $machine['connectionType'] = $isFakeSerial ? 'fakeSerial' : 'serial';
                $machine['simulated'] = $machine['connectionType'] === 'fakeSerial';

                $isFastReconnect = $printerAtNode
                    && (int) $printerAtNode->baudRate === $baudRate
                    && $this->machinesMatch($printerAtNode, $machine);

                if ($isFastReconnect) {
                    $printer = $printerAtNode;
                    $printer->connected = true;
                    $printer->save();

                    $this->info("Known printer reconnected at {$device} using {$baudRate} bps.");
                    $log->info("Known printer reconnected at {$device} using {$baudRate} bps without regenerating its machine profile.");
                } else {
                    $printer = $this->findExistingPrinter($machine, $device, $isFakeSerial, $printerAtNode);
                    $cameras = $printer?->cameras ?: [];

                    if (! $printer) {
                        $printer = new Printer;

                        $changeCount++;
                    }

                    $printer->node = $device;
                    $printer->baudRate = $baudRate;
                    $printer->machine = $machine;
                    $printer->cameras = $cameras;
                    $printer->connected = true;

                    if (! isset($printer->recordableCameras)) {
                        $printer->recordableCameras = [];
                    }

                    $printer->save();

                    $this->info('Printer found! Node name is "'.$device.'", baud rate is '.$baudRate.' bps. Response was: '.$response);
                    $log->info('Printer found! Node name is "'.$device.'", baud rate is '.$baudRate.' bps. Response was: '.$response);
                }

                $printer->setConnectionStatus(Printer::CONNECTION_STATUS_ONLINE);
                $printer->updateLastSeen();

                $changes = $printer->getChanges();

                unset($changes['created_at']);
                unset($changes['updated_at']);

                if ($changes) {
                    $changeCount++;
                }

                for ($extruderIndex = 0; $extruderIndex < $machine['extruderCount']; $extruderIndex++) {
                    $refreshMapperBusy();
                    $statisticsResponse = $serial->query('M105 T'.$extruderIndex);

                    if (! Str::contains($statisticsResponse, 'ok')) {
                        break;
                    }

                    $printer->setStatistics($statisticsResponse, $extruderIndex);
                }

                foreach (
                    Printer::select('node')
                        ->where('node', $printer->node)
                        ->whereRaw(['_id' => ['$ne' => new ObjectId($printer->_id)]])
                        ->cursor() as $matchingNodePrinter
                ) {
                    $matchingNodePrinter->node = null;
                    $matchingNodePrinter->save();
                }

                if ($isFakeSerial) {
                    $this->disconnectDuplicateFakePrinters($printer, $baseUuid);
                }

                return true;
            } finally {
                $serial?->close();
            }
        }
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $log = Log::channel('serial-mapper');
        $target = $this->argument('node');

        $debounceSecs = filter_var(
            $this->option('debounce'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]]
        );

        if ($debounceSecs === false) {
            $this->error('The --debounce option must be a non-negative integer.');

            return Command::INVALID;
        }

        $debouncer = app(SerialMapperDebouncer::class);

        if ($debounceSecs > 0) {
            $log->debug("Waiting for {$debounceSecs} quiet seconds before mapping serial devices.");
        }

        [$shouldRun, $debounceToken] = $debouncer->wait($debounceSecs);

        if (! $shouldRun) {
            $log->debug('Skipping a serial mapper invocation superseded during its debounce window.');

            return Command::SUCCESS;
        }

        $mapperLock = Cache::lock(
            config('cache.serial_mapper_lock_key'),
            self::MAPPER_LOCK_TTL_SECS
        );

        try {
            $mapperLock->block(self::MAPPER_LOCK_WAIT_SECS);
        } catch (LockTimeoutException $lockTimeoutException) {
            $log->warning('Timed out waiting for the active serial mapper: '.$lockTimeoutException->getMessage());
            $this->warn('Timed out waiting for the active serial mapper.');

            return Command::FAILURE;
        }

        try {
            if (! $debouncer->isCurrent($debounceToken)) {
                $log->debug('Skipping a serial mapper invocation superseded while waiting for the mapper lock.');

                return Command::SUCCESS;
            }

            $negotiationWaitSecs = Configuration::get('negotiationWaitSecs');
            $negotiationTimeoutSecs = Configuration::get('negotiationTimeoutSecs');
            $negotiationMaxRetries = Configuration::get('negotiationMaxRetries');

            $baudRates = config('app.common_baud_rates');
            $cacheMapperBusyKey = config('cache.mapper_busy_key');
            $fakeSerialManager = app(FakeSerialManager::class);

            $refreshMapperBusy = static function () use ($cacheMapperBusyKey): void {
                Cache::put(
                    key: $cacheMapperBusyKey,
                    value: true,
                    ttl: self::MAPPER_BUSY_TTL_SECS
                );
            };
            $waitWhileRefreshingMapperBusy = static function (int $waitSecs) use ($refreshMapperBusy): void {
                $waitUntilMillis = millis() + (max(0, $waitSecs) * 1000);

                while (millis() < $waitUntilMillis) {
                    $remainingSecs = ($waitUntilMillis - millis()) / 1000;

                    sleep((int) max(1, min(self::MAPPER_BUSY_HEARTBEAT_SECS, ceil($remainingSecs))));
                    $refreshMapperBusy();
                }
            };

            try {
                $devices = array_values(array_unique(array_merge(
                    Arr::where(
                        scandir(Serial::TERMINAL_PATH),
                        function ($node) {
                            return
                                Str::startsWith($node, Serial::TERMINAL_PREFIX.'ACM')
                                ||
                                Str::startsWith($node, Serial::TERMINAL_PREFIX.'USB');
                        }
                    ),
                    Arr::map(
                        $fakeSerialManager->listVirtualNodes(),
                        fn ($node) => Serial::TERMINAL_PREFIX.$node
                    )
                )));

                if (filled($target)) {
                    $log->debug("The node \"{$target}\" was selected for this mapper instance.");

                    $devices = array_values(array_filter(
                        array: $devices,
                        callback: function ($node) use ($target) {
                            return Str::endsWith($node, $target);
                        }
                    ));
                }

                if (empty($devices)) {
                    if ($target) {
                        $log->info("The selected device node ({$target}) couldn't be found.");
                    } else {
                        $log->info('No devices detected.');
                    }
                }

                $refreshMapperBusy();
                PrintersMapInProgress::dispatch();

                $changeCount = 0;
                $serialPluginHooks = app(PluginHookCompiler::class)->compileSerialHooks();
                $waitedForPrinterBoot = false;

                $waitForPrinterBoot = function () use (
                    &$waitedForPrinterBoot,
                    $negotiationWaitSecs,
                    $waitWhileRefreshingMapperBusy
                ): void {
                    if ($waitedForPrinterBoot) {
                        return;
                    }

                    $this->info("Waiting {$negotiationWaitSecs} seconds for the printer to boot before trying full baud-rate negotiation...");
                    $waitWhileRefreshingMapperBusy($negotiationWaitSecs);
                    $waitedForPrinterBoot = true;
                };

                if (empty($devices)) {
                    $this->info("As no devices are currently connected, the {$negotiationWaitSecs} seconds wait will be skipped.");
                }

                foreach ($devices as $device) {
                    $device = Str::replaceFirst('tty', '', $device);
                    $this->info('Probing for printers at "'.$device.'" node...');

                    $printerAtNode = Printer::where('node', $device)->first();
                    $savedBaudRate = $printerAtNode?->baudRate;
                    $fastRetryCount = 0;

                    if (
                        $printerAtNode
                        &&
                        is_numeric($savedBaudRate)
                        &&
                        $this->tryMapDeviceAtBaud(
                            log: $log,
                            device: $device,
                            baudRate: (int) $savedBaudRate,
                            negotiationTimeoutSecs: $negotiationTimeoutSecs,
                            negotiationMaxRetries: 0,
                            serialPluginHooks: $serialPluginHooks,
                            fakeSerialManager: $fakeSerialManager,
                            printerAtNode: $printerAtNode,
                            refreshMapperBusy: $refreshMapperBusy,
                            waitWhileRefreshingMapperBusy: $waitWhileRefreshingMapperBusy,
                            retryCount: $fastRetryCount,
                            changeCount: $changeCount
                        )
                    ) {
                        continue;
                    }

                    $waitForPrinterBoot();

                    $baudRatesToTry = array_values(array_unique(array_map(
                        'intval',
                        array_filter(
                            array_merge([$savedBaudRate], $baudRates),
                            fn ($baudRate) => is_numeric($baudRate)
                        )
                    )));
                    $negotiationRetryCount = 0;

                    foreach ($baudRatesToTry as $baudRate) {
                        if ($this->tryMapDeviceAtBaud(
                            log: $log,
                            device: $device,
                            baudRate: $baudRate,
                            negotiationTimeoutSecs: $negotiationTimeoutSecs,
                            negotiationMaxRetries: $negotiationMaxRetries,
                            serialPluginHooks: $serialPluginHooks,
                            fakeSerialManager: $fakeSerialManager,
                            printerAtNode: $printerAtNode,
                            refreshMapperBusy: $refreshMapperBusy,
                            waitWhileRefreshingMapperBusy: $waitWhileRefreshingMapperBusy,
                            retryCount: $negotiationRetryCount,
                            changeCount: $changeCount
                        )) {
                            break;
                        }
                    }
                }

                foreach (Printer::all() as $printer) {
                    if (
                        ! Serial::nodeExists($printer->node)
                        &&
                        $printer->connected
                    ) {
                        $printer->connected = false;
                        $printer->setConnectionStatus(Printer::CONNECTION_STATUS_OFFLINE);
                        $printer->save();

                        $changeCount++;
                    }
                }

                return Command::SUCCESS;
            } finally {
                Cache::forget($cacheMapperBusyKey);

                try {
                    PrintersMapUpdated::dispatch();
                } catch (Throwable $exception) {
                    $log->warning('Could not dispatch the completed printer map event: '.$exception->getMessage());
                }
            }
        } finally {
            $mapperLock->release();
        }
    }
}
