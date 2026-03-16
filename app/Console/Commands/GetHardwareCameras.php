<?php

namespace App\Console\Commands;

use App\Models\Camera;
use App\Support\HardwareCameraEnvironmentSerializer;

use Illuminate\Console\Command;

class GetHardwareCameras extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get:hardware-cameras {--if-enabled}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get hardware cameras configuration pre-processed to be directly evaluated by Bash.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $ifEnabled = $this->option('if-enabled');

        $cameras =
            $ifEnabled
                ? Camera::where('enabled', true)->cursor()
                : Camera::cursor();

        foreach ($cameras as $camera) {
            echo HardwareCameraEnvironmentSerializer::serialize($camera->toArray());
        }

        return Command::SUCCESS;
    }
}
