<?php

namespace App\Console\Commands;

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

        $minPollIntervalSecs          = Configuration::get('lastSeenPollIntervalSecs');
        $commandTimeoutSecs           = Configuration::get('commandTimeoutSecs');
        $autoSerialIntervalSecs       = Configuration::get('autoSerialIntervalSecs');
        $maxTimeBetweenHeartbeatsSecs = Configuration::get('lastSeenThresholdSecs');

        $enabled = enabled('terminal.auto_temperature_query');

        if (!$enabled) {
            $log->info( 'This feature has been disabled.' );

            while (true) {
                sleep( 60 * 60 * 24 * 365 ); // 1 year
            }

            return Command::SUCCESS;
        }

        $log->info("Activating in {$autoSerialIntervalSecs} seconds...");

        while (true) {
            sleep( $autoSerialIntervalSecs );

            foreach (Printer::cursor() as $printer) {
                if (mapperIsRunning()) {
                    event(new \App\Events\PrinterMapperIsRunning($printer->_id));

                    sleep(1);

                    $log->debug( __METHOD__ . '@' . __LINE__ . ": {$printer->_id}: the mapper is running, skipping..." );

                    continue;
                }

                if ($printer->activeFile) {
                    $log->debug( __METHOD__ . '@' . __LINE__ . ": {$printer->_id}: active file detected ({$printer->activeFile}), skipping..." );

                    event(
                        new \App\Events\PrinterConnectionStatusUpdated(
                            printerId:      $printer->_id,
                            thresholdSecs:  $maxTimeBetweenHeartbeatsSecs,
                            hasActiveFile:  true
                        )
                    );

                    continue;
                }

                if (!$printer->node || !Serial::nodeExists( $printer->node )) {
                    $log->debug( __METHOD__ . '@' . __LINE__ . ": {$printer->_id}: missing serial node ({$printer->node}), skipping..." );

                    event(
                        new \App\Events\PrinterConnectionStatusUpdated(
                            printerId:      $printer->_id,
                            thresholdSecs:  $maxTimeBetweenHeartbeatsSecs
                        )
                    );

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
                                if (mapperIsRunning()) {
                                    $log->debug( __METHOD__ . '@' . __LINE__ . ": {$printer->_id}: the mapper is running, skipping statistics update..." );

                                    continue;
                                }

                                if ($extruderIndex == 0) { continue; }

                                $printer->setStatistics( $serial->query('M105 T' . $extruderIndex), $extruderIndex );
                            }
                        }

                        $log->debug("OK: {$printer->node}: {$response}");

                        $printer->updateLastSeen();

                        event(
                            new \App\Events\PrinterConnectionStatusUpdated(
                                printerId:      $printer->_id,
                                thresholdSecs:  $maxTimeBetweenHeartbeatsSecs
                            )
                        );
                    }
                } catch (Exception $exception) {
                    $printer->setLastError( $exception->getMessage() );

                    $log->error(
                        $printer->node . ': connection failed: ' . $exception->getMessage() . PHP_EOL .
                        PHP_EOL .
                        $exception->getTraceAsString()
                    );

                    event(
                        new \App\Events\PrinterConnectionStatusUpdated(
                            printerId:      $printer->_id,
                            thresholdSecs:  $maxTimeBetweenHeartbeatsSecs
                        )
                    );

                    continue;
                }
            }
        }

        return Command::FAILURE;
    }
}
