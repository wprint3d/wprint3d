<?php

namespace App\Console\Commands;

use App\Models\Printer;

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

    private function refreshWorkers(array $queues, int $sleepSecs): bool {
        $didChange = false;

        $minWorkers = Printer::where('activeFile', '!=', null)->count();

        foreach ($queues as $queue) {
            $fields = explode(':', $queue);

            $queueName          = $fields[0];
            $enforcedMinWorkers = $fields[1] ?? null;

            $this->comment("Checking queue: {$queueName}...");

            if ($enforcedMinWorkers === null) {
                $enforcedMinWorkers = $minWorkers;
            }

            if ($enforcedMinWorkers == 0) {
                if (file_exists("/tmp/supervisor/{$queueName}.conf")) {
                    $this->info("Removing queue: {$queueName}...");

                    unlink("/tmp/supervisor/{$queueName}.conf");

                    $didChange = true;
                }

                continue;
            }

            $configFile  = '';
            $configFile .= "[program:app-{$queueName}-worker]";
            $configFile .= PHP_EOL . 'process_name=%(program_name)s_%(process_num)02d';;
            $configFile .= PHP_EOL . "command=php /var/www/artisan queue:work --queue={$queueName} --sleep={$sleepSecs} --timeout=0 --rest=2";
            $configFile .= PHP_EOL . 'autostart=true';
            $configFile .= PHP_EOL . 'autorestart=true';
            $configFile .= PHP_EOL . "numprocs={$enforcedMinWorkers}";
            $configFile .= PHP_EOL . 'redirect_stderr=true';
            $configFile .= PHP_EOL . 'user=root';
            $configFile .= PHP_EOL . "stdout_logfile=/var/www/storage/logs/{$queueName}_worker.log";

            $previousSum = null;

            if (file_exists("/tmp/supervisor/{$queueName}.conf")) {
                $previousSum = md5_file("/tmp/supervisor/{$queueName}.conf");
            }

            $nextSum = md5($configFile);

            if ($previousSum != $nextSum) {
                $didChange = true;

                $this->info("Changes detected for queue $queueName: PREVIOUS_SUM = '$previousSum', NEXT_SUM = '$nextSum'");

                file_put_contents("/tmp/supervisor/{$queueName}.conf", $configFile);
            }
        }

        return $didChange;
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
