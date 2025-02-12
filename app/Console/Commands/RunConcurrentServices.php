<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use Illuminate\Support\Arr;

use Illuminate\Support\Facades\Log;

use NHor\PcntlParallel\Messages\WorkerExceptionMessage;
use NHor\PcntlParallel\ParallelTasks;

use Throwable;

class RunConcurrentServices extends Command
{

    protected $signature = 'concurrent:run-indefinitely {--services=}';

    protected $description = 'Run multiple services concurrently';

    public function handle()
    {
        $availableServices = listServices();

        $services = explode(',', $this->option('services'));

        if (empty($services) || $services[0] === '') {
            $services = $availableServices;            
        }

        $services = array_filter($services, function ($service) use ($availableServices) {
            $matched = in_array($service, $availableServices);

            if (!$matched) {
                $this->error("Service '{$service}' not found.");
            }

            return $matched;
        });

        if (empty($services)) {
            $this->error('No services matched the provided list.');

            return;
        }

        $this->info('Starting services: ' . implode(', ', $services));

        $concurrentCallbacks = Arr::map(
            array:      $services,
            callback:   function ($service) {
                return function () use ($service) {
                    $log = Log::channel('concurrent-runner');

                    while (true) {
                        try {
                            $log->info("Service '{$service}' started.");

                            $service = 'App\Console\Services\Concurrent\\' . $service;
                            $service = new $service();
                            $service->handle();
                        } catch (Throwable $exception) {
                            $log->error(
                                "Service '{$service}' failed with message: {$exception->getMessage()} in {$exception->getFile()}:{$exception->getLine()}" . PHP_EOL .
                                $exception->getTraceAsString()
                            );
                        }

                        sleep(1);
                    }
                };
            }
        );

        $parallelTasks = ParallelTasks::add($concurrentCallbacks);
        $parallelTasks->run();

        $this->info('All services started.');

        $output = $parallelTasks->waitOutput(sleepTimeout: 1000000); // 1 second

        foreach ($output as $service => $serviceOutput) {
            if (!($serviceOutput instanceof WorkerExceptionMessage)) { continue; }

            $this->error("Service '{$service}' failed with message: {$serviceOutput->getMessage()}");
        }
    }

}