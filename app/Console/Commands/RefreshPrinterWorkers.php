<?php

namespace App\Console\Commands;

use App\Models\Printer;

use Illuminate\Support\Arr;

use Illuminate\Console\Command;

class RefreshPrinterWorkers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'printers:refresh-workers';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh the amount of active printer workers.';

    // This function creates the queue workers for the jobs that are scalable,
    // these jobs are cancelable and can be restarted without any issues.
    private function createScalableWorkers(array $queues, int $sleepSecs): bool {
        $didChange = false;

        $queues = Arr::where(
            Arr::map($queues, function ($queue) {
                $fields = explode(':', $queue);

                return [
                    'name'        => $fields[0],
                    'min_workers' => $fields[1] ?? null,
                ];
            }, $queues),
            function ($queue) {
                return $queue['min_workers'] !== null;
            }
        );

        $minWorkers = Printer::where('activeFile', '!=', null)->count();

        foreach ($queues as $queue) {
            $this->comment(__METHOD__ . ": checking queue: {$queue['name']}...");

            if ($minWorkers < $queue['min_workers']) {
                $minWorkers = $queue['min_workers'];
            }

            if ($minWorkers == 0) {
                if (file_exists("/tmp/supervisor/{$queue['name']}.conf")) {
                    $this->info("Removing queue: {$queue['name']}...");

                    unlink("/tmp/supervisor/{$queue['name']}.conf");

                    $didChange = true;
                }

                continue;
            }

            $configFile  = '';
            $configFile .= "[program:app-{$queue['name']}-worker]";
            $configFile .= PHP_EOL . 'process_name=%(program_name)s_%(process_num)02d';;
            $configFile .= PHP_EOL . "command=php /var/www/artisan queue:work --queue={$queue['name']} --sleep={$sleepSecs} --timeout=0 --rest=2";
            $configFile .= PHP_EOL . 'autostart=true';
            $configFile .= PHP_EOL . 'autorestart=true';
            $configFile .= PHP_EOL . "numprocs={$minWorkers}";
            $configFile .= PHP_EOL . 'redirect_stderr=true';
            $configFile .= PHP_EOL . 'user=root';
            $configFile .= PHP_EOL . "stdout_logfile=/var/www/storage/logs/{$queue['name']}_worker.log";

            $previousSum = null;

            if (file_exists("/tmp/supervisor/{$queue['name']}.conf")) {
                $previousSum = md5_file("/tmp/supervisor/{$queue['name']}.conf");
            }

            $nextSum = md5($configFile);

            if ($previousSum != $nextSum) {
                $didChange = true;

                $this->info(__METHOD__ . ": changes detected for queue {$queue['name']}: PREVIOUS_SUM = '$previousSum', NEXT_SUM = '$nextSum'");

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

        $queues = Arr::where(
            Arr::map($queues, function ($queue) {
                $fields = explode(':', $queue);

                return [
                    'name'        => $fields[0],
                    'min_workers' => $fields[1] ?? null,
                ];
            }, $queues),
            function ($queue) {
                return $queue['min_workers'] === null;
            }
        );

        $printers = Printer::select('activeFile')->cursor();

        foreach ($printers as $printer) {
            foreach ($queues as $queue) {
                $this->comment(__METHOD__ . ": checking queue: {$queue['name']} for printer {$printer->id}...");

                if ($printer->activeFile == null) {
                    if (file_exists("/tmp/supervisor/{$queue['name']}_{$printer->id}.conf")) {
                        $this->info("Removing worker for queue {$queue['name']} for printer {$printer->id}...");

                        unlink("/tmp/supervisor/{$queue['name']}_{$printer->id}.conf");

                        $didChange = true;
                    }

                    continue;
                }

                $configFile  = '';
                $configFile .= "[program:app-{$queue['name']}-worker-{$printer->id}]";
                $configFile .= PHP_EOL . 'process_name=%(program_name)s_%(process_num)02d';;
                $configFile .= PHP_EOL . "command=php /var/www/artisan queue:work --queue={$queue['name']} --sleep={$sleepSecs} --timeout=0 --rest=2";
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
                    $this->info(__METHOD__ . ": creating worker for queue {$queue['name']} for printer {$printer->id}...");
                }

                $nextSum = md5($configFile);

                if ($previousSum != $nextSum) {
                    $didChange = true;

                    $this->info(__METHOD__ . ": changes detected for queue {$queue['name']} for printer {$printer->id}: PREVIOUS_SUM = '$previousSum', NEXT_SUM = '$nextSum'");

                    file_put_contents("/tmp/supervisor/{$queue['name']}_{$printer->id}.conf", $configFile);
                }
            }
        }

        return $didChange;
    }

    private function refreshWorkers(array $queues, int $sleepSecs): bool {
        return (
            $this->createScalableWorkers($queues, $sleepSecs)
            ||
            $this->createPerPrinterWorkers($queues, $sleepSecs)
        );
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $queues     = explode(',', env('QUEUES'));
        $sleepSecs  = env('SLEEP', 5);

        if (count($queues) == 0) {
            $this->error('No queues configured. Exiting...');

            return Command::FAILURE;
        }

        while (true) {
            $this->comment('Regenerating queue configurations...');

            if ($this->refreshWorkers($queues, $sleepSecs)) {
                $this->info('Reloading supervisor...');

                exec('supervisorctl update');
            }

            time_nanosleep(seconds: $sleepSecs, nanoseconds: 0);
        }

        return Command::FAILURE;
    }
}
