<?php

namespace App\Console\Commands;

use App\Libraries\Serial;

use App\Models\Printer;

use App\Exceptions\TimedOutException;

use Illuminate\Console\Command;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

use MongoDB\BSON\UTCDateTime;

use Exception;
use Illuminate\Support\Facades\DB;

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

        $negotiationTimeoutSecs = env('PRINTER_NEGOTIATION_TIMEOUT_SECS');
        $negotiatonMaxRetries   = env('PRINTER_NEGOTIATION_MAX_RETRIES');

        foreach (
            Arr::where(
                scandir('/dev'),
                function ($node) {
                    return Str::startsWith($node, 'ttyACM') || Str::startsWith($node, 'ttyUSB');
                }
            )
            as $device
        ) {
            $device = Str::replaceFirst('tty', '', $device);

            $this->info('Probing for printers at "' . $device . '" node...');

            $found      = false;
            $retryCount = 0;

            $printer = Printer::where('node', $device)->first();

            if ($printer) {
                $serial = new Serial(
                    fileName: $printer->node,
                    baudRate: $printer->baudRate,
                    timeout:  $negotiationTimeoutSecs
                );

                while (true) {
                    $response = $serial->query('M105');

                    if (containsUTF8($response)) {
                        if (Str::contains( $response, 'ok' )) {
                            $found = true;

                            $this->info('  - Success reloading from stored settings! Got: ' . $response);
                            $log->info ('  - Success reloading from stored settings! Got: ' . $response);

                            break;
                        } else {
                            sleep( $negotiationTimeoutSecs );

                            if ($retryCount >= $negotiatonMaxRetries) break;

                            $retryCount++;

                            $this->info('  - Retrying...');
                            $log->info ('  - Retrying...');

                            continue;
                        }
                    } else {
                        $this->info('  - Probing failed at the expected ' . $printer->baudRate . ' bps: ' . $response);
                        $log->info ('  - Probing failed at the expected ' . $printer->baudRate . ' bps: ' . $response);

                        break;
                    }
                }

                if ($found) continue;
            }

            $found      = false;
            $retryCount = 0;

            foreach (config('app.common_baud_rates') as $baudRate) {
                while (true) {
                    $response = '';

                    try {
                        $serial = new Serial($device, $baudRate, $negotiationTimeoutSecs);

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
                            $this->warn(  '  - At ' . $baudRate . ', this looks like a printer but it didn\'t expose a proper reply, let\'s wait a few seconds and try again. Got: ' . $response);
                            $log->warning('  - At ' . $baudRate . ', this looks like a printer but it didn\'t expose a proper reply, let\'s wait a few seconds and try again. Got: ' . $response);

                            sleep( $negotiationTimeoutSecs );

                            if ($retryCount >= $negotiatonMaxRetries) break;

                            $retryCount++;

                            continue;
                        }

                        $this->info('Printer found! Node name is "' . $device . '", baud rate is ' . $baudRate . ' bps. Response was: ' . $response);

                        $log->info('Printer found! Node name is ' . $device . ', baud rate is ' . $baudRate . ' bps. Response was: ' . $response);

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

                                    $machine['capabilities'][
                                        Str::of( $keyValue[0] )->lower()->camel()->toString()
                                    ] = !!$keyValue[1] ?? false;
                                }
                            }
                        }

                        $cameras = null;

                        if ($printer) {
                            $cameras = $printer->cameras;
                        }

                        if (!$cameras) {
                            $cameras = [];
                        }

                        DB::collection( (new Printer())->getTable() )
                          ->where('node', $device)
                          ->update([
                                'node'      => $device,
                                'baudRate'  => $baudRate,
                                'machine'   => $machine,
                                'cameras'   => $cameras,
                                'lastSeen'  => new UTCDateTime()
                          ], [ 'upsert' => true ]);

                        $printer = Printer::where('node', $device)->first();

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

                        // Delete if it previously existed and is now invalid.
                        Printer::where('node', $device)->delete();

                        break;
                    }
                }

                if ($found) break;
            }
        }

        return Command::SUCCESS;
    }
}
