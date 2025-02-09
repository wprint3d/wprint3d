<?php

namespace App\Console\Services\Concurrent;

use App\Console\Services\Concurrent\Dependencies\ConcurrentService;

use App\Exceptions\InitializationException;

use App\Models\Printer;

use Illuminate\Log\Logger;

use Illuminate\Support\Arr;

use Illuminate\Support\Facades\Log;

class RefreshPrinterWorkers extends ConcurrentService {

    private Logger $log;

    protected $description = 'Refresh the amount of active printer workers.';

    // This function creates the queue workers for the jobs that are scalable,
    // these jobs are cancelable and can be restarted without any issues.
    private function createScalableWorkers(array $queues, int $sleepSecs): bool {
        $didChange = false;

        $queues = Arr::where(
            $queues,
            function ($queue) {
                return $queue['min_workers'] !== null;
            }
        );

        $minWorkers = Printer::where('activeFile', '!=', null)->count();

        foreach ($queues as $queue) {
            $this->log->debug(__METHOD__ . ": checking queue: {$queue['name']}...");

            if ($minWorkers < $queue['min_workers']) {
                $minWorkers = $queue['min_workers'];
            }

            if ($minWorkers == 0) {
                if (file_exists("/tmp/supervisor/{$queue['name']}.conf")) {
                    $this->log->info("Removing queue: {$queue['name']}...");

                    unlink("/tmp/supervisor/{$queue['name']}.conf");

                    $didChange = true;
                }

                continue;
            }

            $configFile  = '';
            $configFile .= "[program:app-{$queue['name']}-worker]";
            $configFile .= PHP_EOL . 'process_name=%(program_name)s_%(process_num)02d';;
            $configFile .= PHP_EOL . "command=php /var/www/artisan queue:work --queue={$queue['name']} --sleep={$sleepSecs} --timeout=0";
            $configFile .= PHP_EOL . 'autostart=true';
            $configFile .= PHP_EOL . 'autorestart=true';
            $configFile .= PHP_EOL . "numprocs={$minWorkers}";
            $configFile .= PHP_EOL . 'redirect_stderr=true';
            $configFile .= PHP_EOL . 'user=root';
            $configFile .= PHP_EOL . "stdout_logfile=/tmp/supervisor/logs/{$queue['name']}_worker.log";

            $previousSum = null;

            if (file_exists("/tmp/supervisor/{$queue['name']}.conf")) {
                $previousSum = md5_file("/tmp/supervisor/{$queue['name']}.conf");
            }

            $nextSum = md5($configFile);

            if ($previousSum != $nextSum) {
                $didChange = true;

                $this->log->info(__METHOD__ . ": changes detected for queue {$queue['name']}: PREVIOUS_SUM = '$previousSum', NEXT_SUM = '$nextSum'");

                file_put_contents("/tmp/supervisor/{$queue['name']}.conf", $configFile);
            }
        }

        return $didChange;
    }

    // This function creates the queue workers for the jobs that are not
    // scalable, these jobs are not cancelable and can't be restarted safely.
    //
    // Each non-scalable job will have a worker per printer.
    private function createPerPrinterWorkers(array $queues, int $sleepSecs): bool {
        $didChange = false;

        $queues = Arr::where($queues,
            function ($queue) {
                return $queue['min_workers'] === null;
            }
        );

        $printers = Printer::select('activeFile')->cursor();

        $allPrintersInactive = true;

        foreach ($printers as $printer) {
            foreach ($queues as $queue) {
                $this->log->debug(__METHOD__ . ": checking queue: {$queue['name']} for printer {$printer->id}...");

                if ($printer->activeFile === null) { continue; }

                $allPrintersInactive = false;

                $configFile  = '';
                $configFile .= "[program:app-{$queue['name']}-worker-{$printer->id}]";
                $configFile .= PHP_EOL . 'process_name=%(program_name)s_%(process_num)02d';;
                $configFile .= PHP_EOL . "command=php /var/www/artisan queue:work --queue={$queue['name']} --sleep={$sleepSecs} --timeout=0";
                $configFile .= PHP_EOL . 'autostart=true';
                $configFile .= PHP_EOL . 'autorestart=true';
                $configFile .= PHP_EOL . 'numprocs=1';
                $configFile .= PHP_EOL . 'redirect_stderr=true';
                $configFile .= PHP_EOL . 'user=root';
                $configFile .= PHP_EOL . "stdout_logfile=/var/www/storage/logs/{$queue['name']}_worker_{$printer->id}.log";

                $previousSum = null;

                if (file_exists("/tmp/supervisor/{$queue['name']}_{$printer->id}.conf")) {
                    $previousSum = md5_file("/tmp/supervisor/{$queue['name']}_{$printer->id}.conf");
                } else {
                    $this->log->debug(__METHOD__ . ": creating worker for queue {$queue['name']} for printer {$printer->id}...");
                }

                $nextSum = md5($configFile);

                if ($previousSum != $nextSum) {
                    $didChange = true;

                    $this->log->debug(__METHOD__ . ": changes detected for queue {$queue['name']} for printer {$printer->id}: PREVIOUS_SUM = '$previousSum', NEXT_SUM = '$nextSum'");

                    file_put_contents("/tmp/supervisor/{$queue['name']}_{$printer->id}.conf", $configFile);
                }
            }
        }

        // This block removes all non-scalable queue workers if all printers are
        // inactive, this is done to avoid running out of memory. This is a
        // workaround for the fact that the queue workers are not cancelable
        // and, currently, we can't tell who's the owner of a worker.
        if ($allPrintersInactive) {
            $this->log->debug('All printers are inactive. Removing all non-scalable queue workers...');

            foreach ($queues as $queue) {
                $files = glob("/tmp/supervisor/{$queue['name']}_*.conf");

                foreach ($files as $file) {
                    $this->log->info("Removing queue: {$file}...");

                    unlink($file);

                    $didChange = true;
                }
            }
        }

        return $didChange;
    }

    private function refreshWorkers(array $queues, int $sleepSecs): bool {
        $queues = Arr::map($queues, function ($queue) {
            $fields = explode(':', $queue);

            return [
                'name'        => $fields[0],
                'min_workers' => $fields[1] ?? null,
            ];
        }, $queues);

        return (
            $this->createScalableWorkers($queues, $sleepSecs)
            ||
            $this->createPerPrinterWorkers($queues, $sleepSecs)
        );
    }

    public function __construct() {
        $this->log = Log::channel('printer-workers-refresh');
    }

    public function handle(): void {
        $this->log->info('Starting printer workers refresh service...');

        $queues     = explode(',', env('QUEUES'));
        $sleepSecs  = env('SLEEP', 5);

        if (count($queues) == 0) {
            throw new InitializationException('No queues were configured.');
        }

        while (true) {
            $this->log->debug('Regenerating queue configurations...');

            if ($this->refreshWorkers($queues, $sleepSecs)) {
                $this->log->info('Reloading supervisor...');

                exec('supervisorctl update');
            }

            time_nanosleep(seconds: $sleepSecs, nanoseconds: 0);
        }
    }

}