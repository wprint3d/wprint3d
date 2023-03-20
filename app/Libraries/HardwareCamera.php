<?php

namespace App\Libraries;

use Illuminate\Support\Str;

use Symfony\Component\Process\Process;

use Symfony\Component\Process\Exception\ProcessFailedException;

class HardwareCamera {

    private int     $index;
    private string  $node;

    private array   $formats = [];

    private bool    $requiresLibCamera = false;

    const LIB_CAMERA_ALLOWED_FRAMERATES = [ 15, 30, 60 ];

    public function __construct(int $index, string $node, bool $requiresLibCamera = false)
    {
        $this->index = $index;
        $this->node  = $node;
        $this->requiresLibCamera = $requiresLibCamera;
    }

    public function takeSnapshot() {
        $process = new Process([
            'fswebcam',
            '-d', $this->node,
            '--no-banner',
            '-'
        ]);

        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return trim( $process->getOutput() );
    }

    private function loadDiscreteUVCFormats() : void {
        $process = new Process([
            'v4l2-ctl',
            '-d', $this->node,
            '--list-formats-ext'
        ]);

        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $output = Str::of( $process->getOutput() )->trim();

        if ($output->contains('MJPG')) {
            $shouldRecordFormats = false; 

            $index = -1;

            $resolution = null;

            foreach ($output->explode( PHP_EOL ) as $line) {
                $line = Str::of( $line )->trim();

                if ($line->startsWith('[') && $line->contains('MJPG')) {
                    $shouldRecordFormats = true;
                } else if ($line->startsWith('[') && !$line->contains('MJPG')) {
                    $shouldRecordFormats = false;
                }

                if ($shouldRecordFormats) {
                    if ($line->startsWith('Size') && $line->contains('Discrete')) {
                        $index++;

                        $resolution = $line->replace('Size: Discrete ', '');
                    } else if ($line->startsWith('Interval') && $resolution) {
                        $this->formats[ $index ] = $resolution . '@' . $line->replaceMatches('/Interval: Discrete .*\(/', '')->replaceMatches('/ fps.*/', '');

                        $index++;
                    }
                }
            }
        }
    }

    private function loadLibCameraFormats() : void {
        if (!env('LIB_CAMERA_ENABLED', false)) { return; }

        $process = new Process([
            'libcamera-vid',
            '--list-cameras'
        ]);
        
        $process->run();

        if (!$process->isSuccessful()) { return; }

        $output = Str::of( $process->getOutput() )->trim();

        if (!$output->contains('Available cameras')) { return; }

        $currentIndex = null;

        foreach ($output->explode( PHP_EOL ) as $line) {
            $line = Str::of( $line )->trim();

            if ($line->contains('/base/soc')) {
                $currentIndex = (int) $line->toString()[0]; // '0 : imx219 [3280x2464] (/base/soc/i2c0mux/i2c@1/imx219@10)' => '0'

                continue;
            }

            if ($currentIndex === $this->index) {
                $resolution =
                    $line->replaceMatches('/.*: /', '')     // 'Modes: \'SRGGB10_CSI2P\' : 640x480 [206.65 fps - (1000, 752)/1280x960 crop]' => '640x480 [206.65 fps - (1000, 752)/1280x960 crop]'
                         ->replaceMatches('/ \[.*/', '');   // '640x480 [206.65 fps - (1000, 752)/1280x960 crop]' => '640x480'

                foreach (self::LIB_CAMERA_ALLOWED_FRAMERATES as $fps) {
                    $this->formats[] = "{$resolution}@{$fps}";
                }
            }
        }
    }

    public function getCompatibleFormats() : array {
        if ($this->requiresLibCamera) {
            $this->loadLibCameraFormats();
        } else {
            $this->loadDiscreteUVCFormats();
        }

        return $this->formats;
    }

}

?>