<?php

namespace App\Libraries;

use App\Models\Configuration;
use App\Plugins\PluginHookDispatcher;
use Closure;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class HardwareCamera
{
    private int $index;

    private string $node;

    private array $formats = [];

    private Closure $pluginHookDispatcher;

    private bool $supportsMjpeg = false;

    private bool $requiresLibCamera = false;

    const LIB_CAMERA_ALLOWED_FRAMERATES = [15, 30, 60];

    public function __construct(int $index, string $node, bool $requiresLibCamera = false, ?callable $pluginHookDispatcher = null)
    {
        $this->index = $index;
        $this->node = $node;
        $this->requiresLibCamera = $requiresLibCamera;
        $this->pluginHookDispatcher = $this->makePluginHookDispatcher($pluginHookDispatcher);
    }

    private function makePluginHookDispatcher(?callable $pluginHookDispatcher = null): Closure
    {
        if ($pluginHookDispatcher !== null) {
            return Closure::fromCallable($pluginHookDispatcher);
        }

        $dispatcher = app(PluginHookDispatcher::class);

        return static fn (string $hook, array $context = []): array => $dispatcher->dispatch($hook, $context);
    }

    private function dispatchPluginHook(string $hook, array $context = []): array
    {
        return ($this->pluginHookDispatcher)($hook, $context);
    }

    public function takeSnapshot()
    {
        $this->dispatchPluginHook('camera.snapshot.before_take', [
            'index' => $this->index,
            'node' => $this->node,
            'requiresLibCamera' => $this->requiresLibCamera,
        ]);

        $process = new Process([
            'fswebcam',
            '-d', $this->node,
            '--no-banner',
            '-',
        ]);

        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $snapshot = trim($process->getOutput());

        $this->dispatchPluginHook('camera.snapshot.after_take', [
            'index' => $this->index,
            'node' => $this->node,
            'requiresLibCamera' => $this->requiresLibCamera,
            'snapshot' => $snapshot,
        ]);

        return $snapshot;
    }

    private function loadFormatsFromDiscreteUVC(Stringable $input, string $captureType): void
    {
        $shouldRecordFormats = false;

        $index = -1;

        $resolution = null;

        foreach ($input->explode(PHP_EOL) as $line) {
            $line = Str::of($line)->trim();

            if ($line->startsWith('[') && $line->contains($captureType)) {
                $shouldRecordFormats = true;
            } elseif ($line->startsWith('[') && ! $line->contains($captureType)) {
                $shouldRecordFormats = false;
            }

            if ($shouldRecordFormats) {
                if ($line->startsWith('Size') && $line->contains('Discrete')) {
                    $index++;

                    $resolution = $line->replace('Size: Discrete ', '');
                } elseif ($line->startsWith('Interval') && $resolution) {
                    $this->formats[] = $resolution.'@'.$line->replaceMatches('/Interval: Discrete .*\(/', '')->replaceMatches('/ fps.*/', '');

                    $index++;
                }
            }
        }
    }

    private function loadDiscreteUVCFormats(): void
    {
        $process = new Process([
            'v4l2-ctl',
            '-d', $this->node,
            '--list-formats-ext',
        ]);

        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $output = Str::of($process->getOutput())->trim();

        if ($output->contains('MJPG') && ! env('DEBUG_CAMERA_MJPEG_DISABLED', false)) {
            $this->loadFormatsFromDiscreteUVC(input: $output, captureType: 'MJPG');
        }

        if ($this->formats) {
            $this->supportsMjpeg = true;
        } elseif ($output->contains('YUYV')) {
            $this->loadFormatsFromDiscreteUVC(input: $output, captureType: 'YUYV');
        }
    }

    private function loadLibCameraFormats(): void
    {
        if (! Configuration::get('enableLibCamera')) {
            return;
        }

        $process = new Process([
            'libcamera-vid',
            '--list-cameras',
        ]);

        $process->run();

        if (! $process->isSuccessful()) {
            return;
        }

        $output = Str::of($process->getOutput())->trim();

        if (! $output->contains('Available cameras')) {
            return;
        }

        $currentIndex = null;

        foreach ($output->explode(PHP_EOL) as $line) {
            $line = Str::of($line)->trim();

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

    public function getCompatibleFormats(): array
    {
        if ($this->requiresLibCamera) {
            $this->loadLibCameraFormats();
        } else {
            $this->loadDiscreteUVCFormats();
        }

        return $this->formats;
    }

    public function supportsMjpeg(): bool
    {
        return $this->supportsMjpeg;
    }
}
