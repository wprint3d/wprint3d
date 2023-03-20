<?php

namespace App\Console\Commands;

use App\Models\Camera;

use Illuminate\Console\Command;

use Illuminate\Support\Str;

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
        }

        return Command::SUCCESS;
    }
}
