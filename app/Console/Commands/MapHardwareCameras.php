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

class MapHardwareCameras extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'map:hardware-cameras';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-map hardware cameras to the caching database.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $log = Log::channel('hardware-cameras-mapper');

        foreach (
            Arr::where(
                scandir('/dev'),
                function ($node) { return Str::startsWith($node, 'video'); }
            )
            as $device
        ) {
            $device = Str::replaceFirst('video', '', $device);

            $this->info('Probing for cameras at "' . $device . '" node...');
            $log->info( 'Probing for cameras at "' . $device . '" node...');

            $camera = Camera::where('node', $device)->first();

            $hwCamera = new HardwareCamera($device);

            $formats = $hwCamera->getCompatibleFormats();

            if (!$formats) {
                $this->info("{$device}: no compatible formats were detected.");
                $log->info( "{$device}: no compatible formats were detected.");

                continue;
            }

            $currentFormat = null;

            if ($camera) $currentFormat = $camera->format;

            if (!in_array($currentFormat, $formats)) {
                $currentFormat = $formats[0];
            }

            $mode = CameraMode::LIVE;

            if ($camera && $camera->mode) $mode = $camera->mode;

            $fields = [
                'node'              => $device,
                'mode'              => $mode,
                'format'            => $currentFormat,
                'availableFormats'  => $formats,
                'requiresLibCamera' => $hwCamera->getRequiresLibCamera()
            ];

            if (!isset( $camera->enabled )) $fields['enabled'] = true;

            DB::collection( (new Camera())->getTable() )
              ->where('node', $device)
              ->update($fields, [ 'upsert' => true ]);
        }

        return Command::SUCCESS;
    }
}
