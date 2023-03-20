<?php

namespace App\Console\Commands;

use App\Models\Camera;

use Illuminate\Console\Command;

use Illuminate\Support\Facades\Log;

class SetHardwareCameraLabel extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'map:set-hardware-camera-label
                            { node  : The camera device node, as in /dev/video0 }
                            { label : The label that should be set.             }';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set a label for a local camera.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $log = Log::channel('hardware-cameras-mapper');

        $node   = $this->argument('node');
        $label  = $this->argument('label');

        if (!$label) {
            $label = null;
        }

        $camera = Camera::where('node', $node)->first();

        if (!$camera) {
            $this->error('No such camera.');

            return Command::FAILURE;
        }

        $camera->label = $label;
        $camera->save();

        return Command::SUCCESS;
    }
}
