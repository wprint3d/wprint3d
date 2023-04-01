<?php

namespace App\Console\Commands;

use App\Events\PrintersMapUpdated;

use App\Libraries\Serial;

use App\Models\Configuration;
use App\Models\Printer;

use App\Exceptions\TimedOutException;

use Illuminate\Console\Command;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

use Illuminate\Support\Facades\Log;

use MongoDB\BSON\UTCDateTime;

use Illuminate\Support\Facades\Cache;

use Exception;

class MapSerialPrinters extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'map:serial-printers';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-map serial printers to the caching database.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $log = Log::channel('serial-mapper');

        $negotiationWaitSecs    = Configuration::get('negotiationWaitSecs',    env('PRINTER_NEGOTIATION_WAIT_SECS'));
        $negotiationTimeoutSecs = Configuration::get('negotiationTimeoutSecs', env('PRINTER_NEGOTIATION_TIMEOUT_SECS'));
        $negotiatonMaxRetries   = Configuration::get('negotiationMaxRetries',  env('PRINTER_NEGOTIATION_MAX_RETRIES'));

        $baudRates          = config('app.common_baud_rates');
        $cacheMapperBusyKey = config('cache.mapper_busy_key');

        $devices = Arr::where(
            scandir('/dev'),
            function ($node) {
                return Str::startsWith($node, 'ttyACM') || Str::startsWith($node, 'ttyUSB');
            }
        );

        Cache::put(
            key:   $cacheMapperBusyKey,
            value: true,
            ttl:   ($negotiationTimeoutSecs * count($baudRates) * count($devices)) + $negotiationWaitSecs + 1 // max possible time spent negotiating (+/- 1)
        );

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
                        $this->info('  - Unknown error: ' . $exception->getMessage());

                        $log->info('Unknown error from serial port at node ' . $device . ' while trying with a baud rate of ' . $baudRate . ' bps: ' . $exception->getMessage());

                        break;
                    }

                    if (containsUTF8($response)) {
                        if (!Str::contains( $response, 'ok' )) {
                            $this->warn(  "  - At {$baudRate}, this looks like a printer but it didn't expose a proper reply, let's wait a few seconds and try again. Got: {$response}");
                            $log->warning("  - At {$baudRate}, this looks like a printer but it didn't expose a proper reply, let's wait a few seconds and try again. Got: {$response}");

                            sleep( $negotiationTimeoutSecs );

                            if ($retryCount >= $negotiatonMaxRetries) break;

                            $retryCount++;

                            continue;
                        }

                        $log->debug('Mapping extruders...');

                        $machine = [
                            'capabilities' => []
                        ];

                        foreach (Str::of( $serial->query('M115') )->explode(PHP_EOL) as $line => $info) {
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
                                    $keyValue = $info->replaceFirst('Cap:', '')->explode(':');

                                    try {
                                        $machine['capabilities'][
                                            Str::of( $keyValue[0] )->lower()->camel()->toString()
                                        ] = !!$keyValue[1] ?? false;
                                    } catch (Exception $exception) {
                                        $this->warn(  "  -> Parse error: {$exception->getMessage()}");
                                        $log->warning("  -> Parse error: {$exception->getMessage()}");
                                    }
                                }
                            }
                        }

                        if (!isset( $machine['uuid'] )) {
                            $this->info('Invalid printer (no UUID available).');
                            $log->info( 'Invalid printer (no UUID available).');

                            continue;
                        }

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

                        $printer = Printer::where('machine.uuid', $machine['uuid'])->first();

                        if (!$printer) {
                            $printer = new Printer();
                        }

                        $printer->node      = $device;
                        $printer->baudRate  = $baudRate;
                        $printer->machine   = $machine;
                        $printer->cameras   = $cameras;
                        $printer->lastSeen  = new UTCDateTime();
                        $printer->save();

                        $changes = $printer->getChanges();

                        if ($changes) {
                            unset( $changes['created_at'] );
                            unset( $changes['updated_at'] );

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

                        break;
                    } else {
                        $this->info('  - Unexpected response: ' . $response);

                        break;
                    }
                }

                if ($found) break;
            }
        }

        Cache::forget( $cacheMapperBusyKey );

        if ($changeCount) {
            PrintersMapUpdated::dispatch();
        }

        return Command::SUCCESS;
    }
}
