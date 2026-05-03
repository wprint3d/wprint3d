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
    private ?Logger $log = null;

    protected $description = 'Poll printers for their connection status.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): void
    {
        $minPollIntervalSecs = Configuration::get('lastSeenPollIntervalSecs');
        $commandTimeoutSecs = Configuration::get('commandTimeoutSecs');
        $autoSerialIntervalSecs = Configuration::get('autoSerialIntervalSecs');
        $maxTimeBetweenHeartbeatsSecs = Configuration::get('lastSeenThresholdSecs');
        $serialPluginHooks = app(PluginHookCompiler::class)->compileSerialHooks();

        $enabled = enabled('terminal.auto_temperature_query');

        if (! $enabled) {
            $this->logger()->info('This feature has been disabled.');

            while (true) {
                sleep(60 * 60 * 24 * 365); // 1 year
            }

            return;
        }

        $this->logger()->info("Activating in {$autoSerialIntervalSecs} seconds...");

        while (true) {
            sleep($autoSerialIntervalSecs);

            try {
                $this->pollPrinters(
                    Printer::cursor(),
                    $minPollIntervalSecs,
                    $commandTimeoutSecs,
                    $maxTimeBetweenHeartbeatsSecs,
                    $serialPluginHooks
                );
            } catch (Throwable $exception) {
                $this->logger()->error(
                    "couldn't poll printers: {$exception->getMessage()}".PHP_EOL.
                    PHP_EOL.
                    $exception->getTraceAsString()
                );
            }
        }
    }

    protected function pollPrinters(
        iterable $printers,
        int $minPollIntervalSecs,
        int $commandTimeoutSecs,
        int $maxTimeBetweenHeartbeatsSecs,
        array $serialPluginHooks
    ): void {
        foreach ($printers as $printer) {
            $this->logger()->debug(__METHOD__.'@'.__LINE__.": {$printer->_id}: checking...");

            if (mapperIsRunning()) {
                event(new \App\Events\PrinterMapperIsRunning($printer->_id));

                $this->logger()->debug(__METHOD__.'@'.__LINE__.": {$printer->_id}: the mapper is running, skipping...");

                sleep(1);

                continue;
            }

            if ($printer->activeFile) {
                $this->logger()->debug(__METHOD__.'@'.__LINE__.": {$printer->_id}: active file detected ({$printer->activeFile}), skipping...");

                event(
                    new \App\Events\PrinterConnectionStatusUpdated(
                        printerId: $printer->_id,
                        thresholdSecs: $maxTimeBetweenHeartbeatsSecs,
                        hasActiveFile: true
                    )
                );

                continue;
            }

            if (! $printer->node || ! $this->serialNodeExists($printer->node)) {
                $this->logger()->debug(__METHOD__.'@'.__LINE__.": {$printer->_id}: missing serial node ({$printer->node}), skipping...");

                $this->markPrinterAsDisconnected($printer);

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
                $serial = $this->makeSerialConnection($printer, $commandTimeoutSecs, $serialPluginHooks);
            } catch (Throwable $exception) {
                if (
                    $exception instanceof InitializationException
                    && str_contains($exception->getMessage(), 'already in use')
                ) {
                    $this->logger()->debug(
                        "{$printer->node}: serial connection is busy, skipping this poll cycle."
                    );

                    continue;
                }

                $this->logger()->error(
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
                ) {
                    $response = $serial->query('M105');

                    if (! Str::contains($response, 'ok') && ! Str::contains($response, 'busy')) {
                        $this->logger()->error($printer->node.': connection failed: '.$response);

                        $printer->setLastError($response);

                        continue;
                    }

                    $printer->setStatistics($response, 0);

                    $statistics = $printer->getStatistics();

                    if (isset($statistics['extruders'])) {
                        foreach (array_keys($statistics['extruders']) as $extruderIndex) {
                            if (mapperIsRunning()) {
                                $this->logger()->debug(__METHOD__.'@'.__LINE__.": {$printer->_id}: the mapper is running, skipping statistics update...");

                                continue;
                            }

                            if ($extruderIndex == 0) {
                                continue;
                            }

                            $printer->setStatistics($serial->query('M105 T'.$extruderIndex), $extruderIndex);
                        }
                    }

                    $this->logger()->debug("OK: {$printer->node}: {$response}");

                    $printer->updateLastSeen();
                    $this->markPrinterAsConnected($printer);

                    event(
                        new \App\Events\PrinterConnectionStatusUpdated(
                            printerId: $printer->_id,
                            thresholdSecs: $maxTimeBetweenHeartbeatsSecs
                        )
                    );
                }
            } catch (Throwable $exception) {
                $printer->setLastError($exception->getMessage());
                $this->markPrinterAsDisconnected($printer);

                $this->logger()->error(
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
                    $this->logger()->error(
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
    }

    protected function serialNodeExists(?string $node): bool
    {
        return $node !== null && Serial::nodeExists($node);
    }

    protected function makeSerialConnection($printer, int $commandTimeoutSecs, array $serialPluginHooks)
    {
        return new Serial(
            fileName: $printer->node,
            baudRate: $printer->baudRate,
            timeout: $commandTimeoutSecs,
            printerId: $printer->_id,
            pluginHooks: $serialPluginHooks
        );
    }

    protected function markPrinterAsConnected($printer): void
    {
        if ($printer->connected) {
            return;
        }

        $printer->connected = true;
        $printer->save();
    }

    protected function markPrinterAsDisconnected($printer): void
    {
        if (! $printer->connected) {
            return;
        }

        $printer->connected = false;
        $printer->save();
    }

    private function logger(): Logger
    {
        return $this->log ??= Log::channel('printers-poller');
    }
}
