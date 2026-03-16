<?php

namespace App\Libraries;

use App\Models\Configuration;
use App\Plugins\PluginHookCompiler;
use App\Support\CameraRuntimeMetadata;
use Closure;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class HardwareCamera
{
    private int $index;

    private string $node;

    private array $formats = [];

    private array $pluginHooks;

    private bool $supportsMjpeg = false;

    private ?string $captureEncoding = null;

    private bool $requiresLibCamera = false;

    const LIB_CAMERA_ALLOWED_FRAMERATES = [15, 30, 60];

    public function __construct(int $index, string $node, bool $requiresLibCamera = false, array $pluginHooks = [])
    {
        $this->index = $index;
        $this->node = $node;
        $this->requiresLibCamera = $requiresLibCamera;
        $this->pluginHooks = $this->resolvePluginHooks($pluginHooks);
    }

    private function resolvePluginHooks(array $pluginHooks = []): array
    {
        if ($pluginHooks === []) {
            $pluginHooks = app(PluginHookCompiler::class)->compileCameraHooks();
        }

        return [
            'camera.snapshot.before_take' => $this->resolvePluginHookCallable($pluginHooks['camera.snapshot.before_take'] ?? null),
            'camera.snapshot.after_take' => $this->resolvePluginHookCallable($pluginHooks['camera.snapshot.after_take'] ?? null),
        ];
    }

    private function resolvePluginHookCallable(?callable $pluginHook = null): Closure
    {
        if ($pluginHook !== null) {
            return Closure::fromCallable($pluginHook);
        }

        return static fn (array $context = []): array => [];
    }

    private function dispatchPluginHook(string $hook, array $context = []): array
    {
        return ($this->pluginHooks[$hook])($context);
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
        if ((new ExecutableFinder)->find('v4l2-ctl') === null) {
            return;
        }

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
            $this->captureEncoding = 'MJPG';
        } elseif ($output->contains('YUYV')) {
            $this->loadFormatsFromDiscreteUVC(input: $output, captureType: 'YUYV');
            if ($this->formats) {
                $this->captureEncoding = 'YUYV';
            }
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

    public function captureEncoding(): ?string
    {
        return $this->captureEncoding;
    }

    public function streamsMjpeg(): bool
    {
        return $this->requiresLibCamera || CameraRuntimeMetadata::supportsSoftwareMjpeg($this->captureEncoding);
    }
}
