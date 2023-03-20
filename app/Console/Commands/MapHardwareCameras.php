<?php

namespace App\Console\Commands;

use App\Enums\CameraMode;
use App\Libraries\HardwareCamera;

use App\Models\Camera;

use Illuminate\Console\Command;
use Illuminate\Log\Logger;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

use Illuminate\Support\Facades\Log;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

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
    protected $description = 'Re-map hardware cameras to the database.';

    const SCAN_UVC_BASE_PATH = '/dev';
    const SCAN_CSI_BASE_PATH = '/sys/firmware/devicetree';

    private ?Logger $log;

    private function map(int $index, string $node, bool $requiresLibCamera = false) : void {
        $this->info("Probing for cameras at \"{$node}\" node, index {$index}...");

        $camera = Camera::where('node', $node)->first();

        $hwCamera = new HardwareCamera(
            index:  $index,
            node:   $node,
            requiresLibCamera: $requiresLibCamera
        );

        $formats = $hwCamera->getCompatibleFormats();

        if (!$formats) {
            $this->info("{$node}: no compatible formats were detected.");

            return;
        }

        $currentFormat = null;

        if ($camera) $currentFormat = $camera->format;

        if (!in_array($currentFormat, $formats)) {
            $currentFormat = $formats[0];
        }

        $mode = CameraMode::LIVE;

        if ($camera && $camera->mode) $mode = $camera->mode;

        $fields = [
            'url'               => env('WEBCAM_BASE_URL') . '/' . ($requiresLibCamera ? 'csi' : 'uvc') . '/' . $index,
            'index'             => $index,
            'node'              => $node,
            'mode'              => $mode,
            'format'            => $currentFormat,
            'availableFormats'  => $formats,
            'requiresLibCamera' => $requiresLibCamera,
        ];

        if (!isset( $camera->enabled )) $fields['enabled'] = true;

        if (!isset( $camera->label ) || !$camera->label) {
            $fields['label'] = 'Unknown camera';
        }

        DB::collection( (new Camera())->getTable() )
          ->where('index', $index)
          ->where('node',  $node)
          ->where('requiresLibCamera', $requiresLibCamera)
          ->update($fields, [ 'upsert' => true ]);
    }

    private function scanUVCDevices() : void {
        foreach (
            Arr::where(
                scandir( self::SCAN_UVC_BASE_PATH ), // /dev
                function ($node) { return Str::startsWith($node, 'video'); }
            )
            as $node
        ) {
            $index = (int) Str::replaceFirst('video', '', $node);

            $this->map(
                index:  $index,
                node:   self::SCAN_UVC_BASE_PATH . '/' . $node
            );
        }
    }

    private function scanLibCameraDevices() : void {
        $process = new Process([
            'libcamera-vid',
            '--list-cameras'
        ]);
        
        $process->run();

        if (!$process->isSuccessful()) {
            $this->log->debug('Failed to query libcamera-vid for video capable libcamera-compatible devices. Message was: ' . $process->getErrorOutput());

            return;
        }

        $output = Str::of( $process->getOutput() )->trim();

        if (!$output->contains('Available cameras')) { return; }

        foreach ($output->explode( PHP_EOL ) as $line) {
            $line = Str::of( $line )->trim();

            if ($line->contains('/base/soc')) {
                // '0 : imx219 [3280x2464] (/base/soc/i2c0mux/i2c@1/imx219@10)' => '0'
                $index = (int) $line->toString()[0];

                // '0 : imx219 [3280x2464] (/base/soc/i2c0mux/i2c@1/imx219@10)'
                //      => '/base/soc/i2c0mux/i2c@1/imx219@10)'
                //          => '/base/soc/i2c0mux/i2c@1/imx219@10'
                $node  = $line->replaceMatches('/.*\(\//', '')->replaceMatches('/\).*/', '');

                $this->map(
                    index:  $index,
                    node:   self::SCAN_CSI_BASE_PATH . '/' . $node,
                    requiresLibCamera: true
                );
            }
        }
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->log = Log::channel('hardware-cameras-mapper');

        $this->scanUVCDevices();
        $this->scanLibCameraDevices();

        return Command::SUCCESS;
    }
}
