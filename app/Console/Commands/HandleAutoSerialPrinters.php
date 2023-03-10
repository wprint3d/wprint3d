<?php

namespace App\Console\Commands;

use App\Events\PrinterConnectionStatusUpdated;
use App\Libraries\Serial;

use App\Models\Printer;

use Illuminate\Console\Command;

use Illuminate\Support\Facades\Log;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

use MongoDB\BSON\UTCDateTime;

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

        $minPollIntervalSecs = env('PRINTER_LAST_SEEN_POLL_INTERVAL_SECS');

        $commandTimeoutSecs = env('PRINTER_COMMAND_TIMEOUT_SECS');

        foreach (Printer::cursor() as $printer) {
            if ($printer->activeFile) { continue; }

            if (!Serial::nodeExists( $printer->node )) {
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
                $queuedCommands = $printer->getResetQueuedCommands();

                foreach ($queuedCommands as $command) {
                    $serial->sendCommand( $command );
                }

                if ($queuedCommands) {
                    $serial->readUntilBlank();

                    $log->info( $printer->node . ': the following commands were processed: ' . Arr::join($queuedCommands, ', ') );
                }

                if (
                    time() - $printer->lastSeen->toDateTime()->getTimestamp()
                    >
                    $minPollIntervalSecs
                ) { // should update lastSeen?
                    $response = $serial->query('M105');

                    if (!Str::contains($response, 'ok') && !Str::contains($response, 'busy')) {
                        $log->error( $printer->node . ': connection failed: ' . $response );

                        $printer->setLastError( $response );

                        continue;
                    }

                    $printer->setStatistics( $response, 0 );

                    $statistics = $printer->getStatistics();

                    foreach (array_keys($statistics['extruders']) as $extruderIndex) {
                        if ($extruderIndex == 0) continue;

                        $printer->setStatistics( $serial->query('M105 T' . $extruderIndex), $extruderIndex );
                    }

                    $log->debug('OK: ' . $response);

                    $printer->lastSeen = new UTCDateTime();
                    $printer->save();

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

        return Command::SUCCESS;
    }
}
