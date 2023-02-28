<?php

namespace App\Console\Commands;

use App\Enums\CameraMode;

use App\Libraries\HardwareCamera;

use App\Models\Camera;

use Illuminate\Console\Command;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

use Illuminate\Support\Facades\Log;

use Illuminate\Support\Facades\DB;

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
            print 'null';

            return Command::SUCCESS;
        }

        $camera = $camera->toArray();

        list($resolution, $framerate) = explode('@', $camera['format']);

        $camera['resolution']   = $resolution;
        $camera['framerate']    = $framerate;

        foreach ($camera as $key => $value) {
            $base = Str::of( $key )->snake()->upper();

            if (is_scalar( $value )) {
                if ($value === null) {
                    $value = 'null';
                } else if (is_bool( $value )) {
                    $value = !!$value ? 1 : 0;
                }

                print $base . '="' . ((string) $value) . '"' . PHP_EOL;
            }
        }

        return Command::SUCCESS;
    }
}
