<?php

namespace App\Libraries;

use Illuminate\Support\Str;

use Symfony\Component\Process\Process;

use Symfony\Component\Process\Exception\ProcessFailedException;

class HardwareCamera {

    private int     $videoId;
    private array   $formats = [];
    private ?bool   $requiresLibCamera = null;

    const VIDEO_PATH   = '/dev';
    const VIDEO_PREFIX = 'video';
    const LIB_CAMERA_ALLOWED_FRAMERATES = [ 15, 30, 60 ];

    public function __construct(int $videoId = 0)
    {
        $this->videoId = $videoId;
    }

    public function takeSnapshot() {
        $process = new Process([
            'fswebcam',
            '-d', self::VIDEO_PATH . '/' . self::VIDEO_PREFIX . $this->videoId,
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
            '-d', self::VIDEO_PATH . '/' . self::VIDEO_PREFIX . $this->videoId,
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
        $process = new Process([
            'libcamera-vid',
            '--list-cameras'
        ]);
        
        $process->run();

        if (!$process->isSuccessful()) { return; }

        $output = Str::of( $process->getOutput() )->trim();

        if (!$output->contains('Available cameras')) { return; }

        $currentNode = null;

        foreach ($output->explode( PHP_EOL ) as $line) {
            $line = Str::of( $line )->trim();

            if ($line->contains('/base/soc')) {
                $currentNode = (int) $line->toString()[0]; // '0 : imx219 [3280x2464] (/base/soc/i2c0mux/i2c@1/imx219@10)' => '0'

                continue;
            }

            if ($currentNode === $this->videoId) {
                $resolution =
                    $line->replaceMatches('/.*: /', '')     // 'Modes: \'SRGGB10_CSI2P\' : 640x480 [206.65 fps - (1000, 752)/1280x960 crop]' => '640x480 [206.65 fps - (1000, 752)/1280x960 crop]'
                         ->replaceMatches('/ \[.*/', '');   // '640x480 [206.65 fps - (1000, 752)/1280x960 crop]' => '640x480'

                foreach (self::LIB_CAMERA_ALLOWED_FRAMERATES as $fps) {
                    $this->formats[] = "{$resolution}@{$fps}";
                }
            }
        }
    }

    private function loadFormats() {
        $this->loadDiscreteUVCFormats();

        $this->requiresLibCamera = false;

        if ($this->videoId == 0 && !$this->formats) {
            $this->loadLibCameraFormats();

            if ($this->formats) {
                $this->requiresLibCamera = true;
            }
        }
    }

    public function getCompatibleFormats() : array {
        if (!$this->formats) {
            $this->loadFormats();
        }

        return $this->formats;
    }

    public function getRequiresLibCamera() : bool {
        if ($this->requiresLibCamera === null) {
            $this->loadFormats();
        }

        return $this->requiresLibCamera;
    }
}

?>