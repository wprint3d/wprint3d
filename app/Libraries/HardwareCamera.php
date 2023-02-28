<?php

namespace App\Libraries;

use Illuminate\Support\Str;

use Symfony\Component\Process\Process;

use Symfony\Component\Process\Exception\ProcessFailedException;

class HardwareCamera {

    private int $videoId;

    const VIDEO_PATH   = '/dev';
    const VIDEO_PREFIX = 'video';

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

    public function getCompatibleFormats() {
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

        $formats = [];

        if ($output->contains('MJPG')) {
            $shouldRecordFormats = false; 

            $index = -1;

            foreach ($output->explode( PHP_EOL ) as $line) {
                $line = Str::of( $line )->trim();

                if ($line->startsWith('[') && $line->contains('MJPG')) {
                    $shouldRecordFormats = true;
                } else if ($line->startsWith('[') && !$line->contains('MJPG')) {
                    $shouldRecordFormats = false;
                }

                if ($shouldRecordFormats) {
                    if ($line->startsWith('Size')) {
                        $index++;

                        $formats[ $index ]  = $line->replace('Size: Discrete ', '');
                    } else if ($line->startsWith('Interval')) {
                        $formats[ $index ] .= '@' . $line->replaceMatches('/Interval: Discrete .*\(/', '')->replaceMatches('/ fps.*/', '');
                    }
                }
            }
        }

        return $formats;
    }
}

?>