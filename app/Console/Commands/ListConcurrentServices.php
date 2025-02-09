<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ListConcurrentServices extends Command
{
    protected $signature = 'concurrent:list';

    protected $description = 'List all concurrent services';

    public function handle() {
        $services = listServices();

        $serviceCount = count($services);

        foreach ($services as $index => $service) {
            $this->info($service);

            $service = new ('App\Console\Services\Concurrent\\' . $service);

            $this->comment($service->getDescription());

            if ($index < $serviceCount - 1) {
                $this->line('');
            }
        }
    }

}