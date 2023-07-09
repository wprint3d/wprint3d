<?php

namespace App\Console\Commands;

use App\Events\PrinterConnectionStatusUpdated;

use App\Models\Configuration;
use App\Models\Printer;

use App\Libraries\Serial;

use Illuminate\Console\Command;

use Illuminate\Support\Facades\Log;

use Illuminate\Support\Str;

use Exception;

class HandleAutoSerialPrinters extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'printers:handle-auto-serial';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Poll printers for their connection status.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $log = Log::channel('printers-poller');

        $minPollIntervalSecs    = Configuration::get('lastSeenPollIntervalSecs');
        $commandTimeoutSecs     = Configuration::get('commandTimeoutSecs');
        $autoSerialIntervalSecs = Configuration::get('autoSerialIntervalSecs');

        $enabled = enabled('terminal.auto_temperature_query');

        if (!$enabled) {
            $log->info( 'This feature has been disabled.' );

            while (true) {
                sleep( 60 * 60 * 24 * 365 ); // 1 year
            }

            return Command::SUCCESS;
        }

        while (true) {
            sleep( $autoSerialIntervalSecs );

            foreach (Printer::cursor() as $printer) {
                // Did we actually have to wait for the mapper?
                if (tryToWaitForMapper($log)) {
                    /*
                     * If so, wait for a second and refresh the printer.
                     * 
                     * This process ensures that we're seeing an up-to-date
                     * version of the document, avoiding writes to a busy
                     * serial connection.
                     */

                    sleep(1);

                    $printer->refresh();
                }

                if ($printer->activeFile) { continue; }

                if (!$printer->node || !Serial::nodeExists( $printer->node )) {
                    PrinterConnectionStatusUpdated::dispatch( $printer->_id );

                    continue;
                }

                $serial = new Serial(
                    fileName:  $printer->node,
                    baudRate:  $printer->baudRate,
                    timeout:   $commandTimeoutSecs,
                    printerId: $printer->_id
                );

                try {
                    $lastSeen = $printer->getLastSeen();

                    if (
                        !$lastSeen
                        ||
                        time() - $lastSeen > $minPollIntervalSecs
                    ) { // should update lastSeen?
                        $response = $serial->query('M105');

                        if (!Str::contains($response, 'ok') && !Str::contains($response, 'busy')) {
                            $log->error( $printer->node . ': connection failed: ' . $response );

                            $printer->setLastError( $response );

                            continue;
                        }

                        $printer->setStatistics( $response, 0 );

                        $statistics = $printer->getStatistics();

                        if (isset( $statistics['extruders'] )) {
                            foreach (array_keys($statistics['extruders']) as $extruderIndex) {
                                if ($extruderIndex == 0) continue;

                                tryToWaitForMapper($log);

                                $printer->setStatistics( $serial->query('M105 T' . $extruderIndex), $extruderIndex );
                            }
                        }

                        $log->debug('OK: ' . $response);

                        $printer->updateLastSeen();

                        PrinterConnectionStatusUpdated::dispatch( $printer->_id );
                    }
                } catch (Exception $exception) {
                    $printer->setLastError( $exception->getMessage() );

                    $log->error(
                        $printer->node . ': connection failed: ' . $exception->getMessage() . PHP_EOL .
                        PHP_EOL .
                        $exception->getTraceAsString()
                    );

                    PrinterConnectionStatusUpdated::dispatch( $printer->_id );

                    continue;
                }
            }
        }

        return Command::FAILURE;
    }
}
