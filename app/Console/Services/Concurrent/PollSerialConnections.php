<?php

namespace App\Console\Services\Concurrent;

use App\Console\Services\Concurrent\Dependencies\ConcurrentService;
use App\Exceptions\InitializationException;
use App\Libraries\Serial;
use App\Models\Configuration;
use App\Models\Printer;
use App\Plugins\PluginHookCompiler;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PollSerialConnections extends ConcurrentService
{
    private Logger $log;

    protected $description = 'Poll printers for their connection status.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): void
    {
        $this->log = Log::channel('printers-poller');

        $minPollIntervalSecs = Configuration::get('lastSeenPollIntervalSecs');
        $commandTimeoutSecs = Configuration::get('commandTimeoutSecs');
        $autoSerialIntervalSecs = Configuration::get('autoSerialIntervalSecs');
        $maxTimeBetweenHeartbeatsSecs = Configuration::get('lastSeenThresholdSecs');
        $serialPluginHooks = app(PluginHookCompiler::class)->compileSerialHooks();

        $enabled = enabled('terminal.auto_temperature_query');

        if (! $enabled) {
            $this->log->info('This feature has been disabled.');

            while (true) {
                sleep(60 * 60 * 24 * 365); // 1 year
            }

            return;
        }

        $this->log->info("Activating in {$autoSerialIntervalSecs} seconds...");

        while (true) {
            sleep($autoSerialIntervalSecs);

            try {
                foreach (Printer::cursor() as $printer) {
                    $this->log->debug(__METHOD__.'@'.__LINE__.": {$printer->_id}: checking...");

                    if (mapperIsRunning()) {
                        event(new \App\Events\PrinterMapperIsRunning($printer->_id));

                        $this->log->debug(__METHOD__.'@'.__LINE__.": {$printer->_id}: the mapper is running, skipping...");

                        sleep(1);

                        continue;
                    }

                    if ($printer->activeFile) {
                        $this->log->debug(__METHOD__.'@'.__LINE__.": {$printer->_id}: active file detected ({$printer->activeFile}), skipping...");

                        event(
                            new \App\Events\PrinterConnectionStatusUpdated(
                                printerId: $printer->_id,
                                thresholdSecs: $maxTimeBetweenHeartbeatsSecs,
                                hasActiveFile: true
                            )
                        );

                        continue;
                    }

                    if (! $printer->node || ! Serial::nodeExists($printer->node)) {
                        $this->log->debug(__METHOD__.'@'.__LINE__.": {$printer->_id}: missing serial node ({$printer->node}), skipping...");

                        event(
                            new \App\Events\PrinterConnectionStatusUpdated(
                                printerId: $printer->_id,
                                thresholdSecs: $maxTimeBetweenHeartbeatsSecs
                            )
                        );

                        continue;
                    }

                    $serial = null;

                    try {
                        $serial = new Serial(
                            fileName: $printer->node,
                            baudRate: $printer->baudRate,
                            timeout: $commandTimeoutSecs,
                            printerId: $printer->_id,
                            pluginHooks: $serialPluginHooks
                        );
                    } catch (Throwable $exception) {
                        if (
                            $exception instanceof InitializationException
                            && str_contains($exception->getMessage(), 'already in use')
                        ) {
                            $this->log->debug(
                                "{$printer->node}: serial connection is busy, skipping this poll cycle."
                            );

                            continue;
                        }

                        $this->log->error(
                            "{$printer->node}: couldn't connect: {$exception->getMessage()}".PHP_EOL.
                            PHP_EOL.
                            $exception->getTraceAsString()
                        );

                        continue;
                    }

                    try {
                        $lastSeen = $printer->getLastSeen();

                        if (
                            ! $lastSeen
                            ||
                            time() - $lastSeen > $minPollIntervalSecs
                        ) { // should update lastSeen?
                            $response = $serial->query('M105');

                            if (! Str::contains($response, 'ok') && ! Str::contains($response, 'busy')) {
                                $this->log->error($printer->node.': connection failed: '.$response);

                                $printer->setLastError($response);

                                continue;
                            }

                            $printer->setStatistics($response, 0);

                            $statistics = $printer->getStatistics();

                            if (isset($statistics['extruders'])) {
                                foreach (array_keys($statistics['extruders']) as $extruderIndex) {
                                    if (mapperIsRunning()) {
                                        $this->log->debug(__METHOD__.'@'.__LINE__.": {$printer->_id}: the mapper is running, skipping statistics update...");

                                        continue;
                                    }

                                    if ($extruderIndex == 0) {
                                        continue;
                                    }

                                    $printer->setStatistics($serial->query('M105 T'.$extruderIndex), $extruderIndex);
                                }
                            }

                            $this->log->debug("OK: {$printer->node}: {$response}");

                            $printer->updateLastSeen();

                            event(
                                new \App\Events\PrinterConnectionStatusUpdated(
                                    printerId: $printer->_id,
                                    thresholdSecs: $maxTimeBetweenHeartbeatsSecs
                                )
                            );
                        }
                    } catch (Throwable $exception) {
                        $printer->setLastError($exception->getMessage());

                        $this->log->error(
                            $printer->node.': connection failed: '.$exception->getMessage().PHP_EOL.
                            PHP_EOL.
                            $exception->getTraceAsString()
                        );

                        try {
                            event(
                                new \App\Events\PrinterConnectionStatusUpdated(
                                    printerId: $printer->_id,
                                    thresholdSecs: $maxTimeBetweenHeartbeatsSecs
                                )
                            );
                        } catch (Throwable $exception) {
                            $this->log->error(
                                "{$printer->node}: couldn't dispatch fallback event: {$exception->getMessage()}".PHP_EOL.
                                PHP_EOL.
                                $exception->getTraceAsString()
                            );
                        }

                        continue;
                    } finally {
                        $serial?->close();
                    }
                }
            } catch (Throwable $exception) {
                $this->log->error(
                    "{$printer->node}: couldn't poll printers: {$exception->getMessage()}".PHP_EOL.
                    PHP_EOL.
                    $exception->getTraceAsString()
                );
            }
        }
    }
}
