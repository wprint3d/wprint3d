<?php

namespace App\Console\Commands;

use App\Events\PrintersMapInProgress;
use App\Events\PrintersMapUpdated;

use App\Libraries\Serial;

use App\Models\Configuration;
use App\Models\Printer;

use App\Exceptions\TimedOutException;

use Illuminate\Console\Command;

use Illuminate\Log\Logger;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

use MongoDB\BSON\ObjectId;

use Exception;

class MapSerialPrinters extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'map:serial-printers
                            { node? : The target device node. i.e.: USB0, ACM0, etc. }';

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
     * @param  Logger $log
     * @param  string $information
     * 
     * @return array
     */
    private function parseFirmwareInformation(Logger $log, string $information) {
        $this->comment("   -> Parsing...");
        $log->debug(   "   -> Parsing...");

        $machine = [
            'capabilities' => []
        ];

        foreach (Str::of( $information )->explode(PHP_EOL) as $line => $info) {
            if ($line == 0) {
                $writingKey = true;

                $key = '';

                for ($index = 0; $index < strlen($info); $index++) {
                    if ($writingKey) {
                        if ($info[ $index ] == ' ') continue;

                        if ($info[ $index ] == ':') {
                            $writingKey = false;

                            $key = Str::of( $key )->lower()->camel()->toString();
                        } else {
                            $key .= $info[ $index ];
                        }
                    } else {
                        if (!isset($machine[ $key ])) {
                            $machine[ $key ] = '';
                        }

                        $machine[ $key ] .= $info[ $index ];

                        if (
                            isset($info[ $index + 1 ]) && $info[ $index + 1] == ' '         // current + 1 must be a space
                            &&
                            isset($info[ $index + 2 ]) && ctype_upper($info[ $index + 2 ])  // current + 2 must be uppercase
                            &&
                            isset($info[ $index + 3 ]) && ctype_upper($info[ $index + 3 ])  // current + 3 must be uppercase
                        ) {
                            $key = '';

                            $writingKey = true;
                        }
                    }
                }
            } else {
                $info = Str::of( $info );

                if ($info->startsWith('Cap:')) {
                    $keyValue = $info->replaceFirst('Cap:', '')->explode(':')->toArray();

                    try {
                        $key    = Str::of( $keyValue[0] )->lower()->camel()->toString();
                        $value  = !!( (int) $keyValue[1]) ?? false;

                        $machine['capabilities'][ $key ] = $value;

                        $this->comment("     -= F: {$key}: " . ($value ? 'true' : 'false'));
                        $log->debug(   "     -= F: {$key}: " . ($value ? 'true' : 'false'));
                    } catch (Exception $exception) {
                        $this->warn(  "     -> Parse error: {$exception->getMessage()}");
                        $log->warning("     -> Parse error: {$exception->getMessage()}");
                    }
                }
            }
        }

        return $machine;
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

        $negotiationWaitSecs    = Configuration::get('negotiationWaitSecs');
        $negotiationTimeoutSecs = Configuration::get('negotiationTimeoutSecs');
        $negotiatonMaxRetries   = Configuration::get('negotiationMaxRetries');

        $baudRates          = config('app.common_baud_rates');
        $cacheMapperBusyKey = config('cache.mapper_busy_key');

        $devices = Arr::where(
            scandir( Serial::TERMINAL_PATH ),
            function ($node) {
                return
                    Str::startsWith($node, Serial::TERMINAL_PREFIX . 'ACM')
                    ||
                    Str::startsWith($node, Serial::TERMINAL_PREFIX . 'USB');
            }
        );

        if (filled( $target )) {
            $log->debug("The node \"{$target}\" was selected for this mapper instance.");

            $devices = array_filter(
                array:    $devices,
                callback: function ($node) use ($target) {
                    return Str::endsWith($node, $target);
                }
            );
        }

        if (empty( $devices )) {
            if ($target) {
                $log->info("The selected device node ({$target}) couldn't be found.");
            } else {
                $log->info('No devices detected.');
            }

            return Command::SUCCESS;
        }

        Cache::put(
            key:   $cacheMapperBusyKey,
            value: true,
            ttl:   ($negotiationTimeoutSecs * count($baudRates) * count($devices)) + $negotiationWaitSecs + 1 // max possible time spent negotiating (+/- 1)
        );

        PrintersMapInProgress::dispatch();

        $this->info("Waiting {$negotiationWaitSecs} seconds for the printer to boot before trying to negotiate a connection...");

        sleep( $negotiationWaitSecs );

        $changeCount = 0;

        foreach ($devices as $device) {
            $device = Str::replaceFirst('tty', '', $device);

            $this->info('Probing for printers at "' . $device . '" node...');

            $found      = false;
            $retryCount = 0;

            foreach ($baudRates as $baudRate) {
                while (true) {
                    $response = '';

                    try {
                        $serial = new Serial(
                            fileName: $device,
                            baudRate: $baudRate,
                            timeout:  $negotiationTimeoutSecs
                        );

                        $response = $serial->query('M105');
                    } catch (TimedOutException $timedOutException) {
                        $this->info('  - No response at ' . $baudRate . ' bps: ' . $timedOutException->getMessage());

                        $log->info('No response from serial port at node ' . $device . ' while trying with a baud rate of ' . $baudRate . ' bps: ' . $timedOutException->getMessage());

                        break;
                    } catch (Exception $exception) {
                        $this->info('  - Negotiation error at ' . $baudRate . ' bps: ' . $exception->getMessage());

                        $log->info('Negotiation error from serial port at node ' . $device . ' while trying with a baud rate of ' . $baudRate . ' bps: ' . $exception->getMessage());

                        break;
                    }

                    if (!Str::contains( $response, 'ok' ) && !containsNonUTF8($response)) {
                        $this->warn(  "  - At {$baudRate}, this looks like a printer but it didn't expose a proper reply, let's wait a few seconds and try again. Got: {$response}");
                        $log->warning("  - At {$baudRate}, this looks like a printer but it didn't expose a proper reply, let's wait a few seconds and try again. Got: {$response}");

                        sleep( $negotiationTimeoutSecs );

                        if ($retryCount >= $negotiatonMaxRetries) break;

                        $retryCount++;

                        continue;
                    }

                    $log->debug('Mapping extruders...');

                    try {
                        $machine = $this->parseFirmwareInformation(
                            log:         $log,
                            information: $serial->query('M115')
                        );
                    } catch (Exception $exception) {
                        $this->info("  -> Something went wrong while trying to gather information about the machine: {$exception->getMessage()}");
                        $log->info( "  -> Something went wrong while trying to gather information about the machine: {$exception->getMessage()}");

                        continue;
                    }

                    if (!isset( $machine['uuid'] )) {
                        $this->info('  -> Invalid printer (no UUID available).');
                        $log->info( '  -> Invalid printer (no UUID available).');

                        break;
                    }

                    $machine['uuid'] =
                        $machine['uuid']
                        . '/' .
                        hash(
                            algo: 'crc32',
                            data: json_encode( $machine )
                        );

                    $cameras = null;

                    $printer = Printer::where('machine.uuid', $machine['uuid'])->first();

                    if ($printer) {
                        $cameras = $printer->cameras;
                    }

                    if (!$cameras) {
                        $cameras = [];
                    }

                    $this->info('Printer found! Node name is "' . $device . '", baud rate is ' . $baudRate . ' bps. Response was: ' . $response);
                    $log->info( 'Printer found! Node name is "' . $device . '", baud rate is ' . $baudRate . ' bps. Response was: ' . $response);

                    if (!$printer) {
                        $printer = new Printer();

                        $changeCount++;
                    }

                    $printer->node      = $device;
                    $printer->baudRate  = $baudRate;
                    $printer->machine   = $machine;
                    $printer->cameras   = $cameras;
                    $printer->connected = true;

                    if (!isset( $printer->recordableCameras )) {
                        $printer->recordableCameras = [];
                    }

                    $printer->save();
                    $printer->updateLastSeen();

                    $changes = $printer->getChanges();

                    unset( $changes['created_at'] );
                    unset( $changes['updated_at'] );

                    if ($changes) {
                        $changeCount++;
                    }

                    $extruderIndex = 0;

                    while (true) {
                        $response = $serial->query('M105 T' . $extruderIndex);

                        if (!Str::contains( $response, 'ok' )) break;

                        $printer->setStatistics( $response, $extruderIndex );

                        $extruderIndex++;
                    }

                    $found = true;

                    foreach (
                        Printer::select('node')
                               ->where('node', $printer->node)
                               ->whereRaw([ '_id' => [ '$ne' => new ObjectId( $printer->_id ) ] ])
                               ->cursor()
                        as $matchingNodePrinter
                    ) {
                        $matchingNodePrinter->node = null;
                        $matchingNodePrinter->save();
                    }

                    break;
                }

                if ($found) break;
            }
        }

        foreach (Printer::all() as $printer) {
            if (
                !file_exists( Serial::TERMINAL_PATH . '/' . Serial::TERMINAL_PREFIX . $printer->node )
                &&
                $printer->connected
            ) {
                $printer->connected = false;
                $printer->save();

                $changeCount++;
            }
        }

        Cache::forget( $cacheMapperBusyKey );

        PrintersMapUpdated::dispatch();

        return Command::SUCCESS;
    }
}
