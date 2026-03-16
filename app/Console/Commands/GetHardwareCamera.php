<?php

namespace App\Console\Commands;

use App\Models\Camera;
use App\Support\HardwareCameraEnvironmentSerializer;

use Illuminate\Console\Command;

class GetHardwareCamera extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get:hardware-camera {node}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get a hardware camera\'s configuration pre-processed to be directly evaluated by Bash.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $node = $this->argument('node');

        $camera = Camera::where('node', $node)->first();

        if (!$camera) {
            echo 'null';

            return Command::SUCCESS;
        }

        echo HardwareCameraEnvironmentSerializer::serialize($camera->toArray());

        return Command::SUCCESS;
    }
}
