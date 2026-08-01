<?php

namespace App\Services\OctoPrint;

use App\Exceptions\PrintJobException;
use App\Models\Printer;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class PrinterControlService
{
    public function sendCommands(Printer $printer, array $commands): void
    {
        $this->withPrinterLock($printer, function () use ($printer, $commands) {
            $this->ensureConnected($printer);
            $this->queue($printer, $commands);
        });
    }

    public function jog(Printer $printer, array $axes, mixed $speed = 1500): void
    {
        $this->withPrinterLock($printer, function () use ($printer, $axes, $speed) {
            $this->ensureConnected($printer);
            $this->ensureIdle($printer);

            $parts = [];

            foreach (['x', 'y', 'z'] as $axis) {
                if (! array_key_exists($axis, $axes)) {
                    continue;
                }

                $distance = $this->number($axes[$axis], -100, 100, strtoupper($axis));

                if ($distance != 0) {
                    $parts[] = strtoupper($axis).$this->formatNumber($distance);
                }
            }

            if ($parts === []) {
                throw new \InvalidArgumentException('At least one axis must move.');
            }

            $feedrate = $this->number($speed, 60, 10000, 'Jog speed');

            $this->queue($printer, [
                'G91',
                'G0 '.implode(' ', $parts).' F'.$this->formatNumber($feedrate),
                'G90',
            ]);
        });
    }

    public function home(Printer $printer, array $axes): void
    {
        $this->withPrinterLock($printer, function () use ($printer, $axes) {
            $this->ensureConnected($printer);
            $this->ensureIdle($printer);

            $normalized = [];

            foreach ($axes as $axis) {
                $axis = strtolower(trim((string) $axis));

                if (! in_array($axis, ['x', 'y', 'z'], true)) {
                    throw new \InvalidArgumentException('Home axes must be X, Y, or Z.');
                }

                $normalized[$axis] = strtoupper($axis);
            }

            if ($normalized === []) {
                throw new \InvalidArgumentException('At least one axis must be homed.');
            }

            $this->queue($printer, ['G28 '.implode(' ', array_values($normalized))]);
        });
    }

    public function setFeedrate(Printer $printer, mixed $factor): void
    {
        $this->queueTuningCommand($printer, 'M220 S', $this->number($factor, 50, 200, 'Feedrate'));
    }

    public function setFlowrate(Printer $printer, mixed $factor): void
    {
        $this->queueTuningCommand($printer, 'M221 S', $this->number($factor, 75, 125, 'Flow rate'));
    }

    public function selectTool(Printer $printer, mixed $tool): void
    {
        $index = $this->toolIndex($tool);

        $this->withPrinterLock($printer, function () use ($printer, $index) {
            $this->ensureConnected($printer);
            $this->queue($printer, ['T'.$index]);
        });
    }

    public function setHotendTarget(Printer $printer, mixed $tool, mixed $temperature): void
    {
        $index = $this->toolIndex($tool);
        $target = $this->number($temperature, 0, 350, 'Hotend temperature');

        $this->withPrinterLock($printer, function () use ($printer, $index, $target) {
            $this->ensureConnected($printer);
            $command = $index === 0
                ? 'M104 S'.$this->formatNumber($target)
                : 'M104 T'.$index.' S'.$this->formatNumber($target);
            $this->queue($printer, [$command]);
        });
    }

    public function setBedTarget(Printer $printer, mixed $temperature): void
    {
        $target = $this->number($temperature, 0, 150, 'Build plate temperature');

        $this->withPrinterLock($printer, function () use ($printer, $target) {
            $this->ensureConnected($printer);
            $this->queue($printer, ['M140 S'.$this->formatNumber($target)]);
        });
    }

    public function extrude(Printer $printer, mixed $tool, mixed $amount, mixed $speed = 300): void
    {
        $index = $this->toolIndex($tool);
        $distance = $this->number($amount, -100, 100, 'Extrusion distance');
        $feedrate = $this->number($speed, 1, 5000, 'Extrusion speed');

        if ($distance == 0) {
            throw new \InvalidArgumentException('Extrusion distance must not be zero.');
        }

        $this->withPrinterLock($printer, function () use ($printer, $index, $distance, $feedrate) {
            $this->ensureConnected($printer);
            $this->ensureIdle($printer);

            $statistics = $printer->getStatistics();
            $temperature = (float) data_get($statistics, 'extruders.'.$index.'.temperature', 0);

            if ($temperature < 170) {
                throw new PrintJobException('cold_extrusion', 'Heat the selected nozzle to at least 170 °C before extruding.');
            }

            $this->queue($printer, [
                'M83',
                'T'.$index,
                'G1 E'.$this->formatNumber($distance).' F'.$this->formatNumber($feedrate),
                'M82',
            ]);
        });
    }

    private function queueTuningCommand(Printer $printer, string $prefix, float $value): void
    {
        $this->withPrinterLock($printer, function () use ($printer, $prefix, $value) {
            $this->ensureConnected($printer);
            $this->queue($printer, [$prefix.$this->formatNumber($value)]);
        });
    }

    private function withPrinterLock(Printer $printer, callable $operation): mixed
    {
        $uuid = data_get($printer, 'machine.uuid', (string) $printer->_id);

        try {
            return Cache::store('redis')->lock('printer-control:'.$uuid, 10)->block(3, $operation);
        } catch (LockTimeoutException) {
            throw new PrintJobException('busy', 'The printer is busy handling another control request.');
        }
    }

    private function ensureConnected(Printer $printer): void
    {
        if (! $printer->connected) {
            throw new PrintJobException('offline', 'The selected printer is offline.');
        }
    }

    private function ensureIdle(Printer $printer): void
    {
        if ($printer->activeFile) {
            throw new PrintJobException('job_active', 'Manual movement and extrusion are unavailable while a print job is active.');
        }
    }

    private function queue(Printer $printer, array $commands): void
    {
        foreach ($commands as $command) {
            if (! $printer->queueCommand($command)) {
                throw new PrintJobException('queue_failed', 'WPrint 3D could not queue the printer command.');
            }
        }
    }

    private function toolIndex(mixed $tool): int
    {
        if (is_string($tool) && preg_match('/^tool(\d+)$/', $tool, $matches)) {
            $tool = $matches[1];
        }

        return (int) $this->number($tool, 0, 15, 'Tool index');
    }

    private function number(mixed $value, float $minimum, float $maximum, string $label): float
    {
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException($label.' must be a number.');
        }

        $number = (float) $value;

        if (! is_finite($number) || $number < $minimum || $number > $maximum) {
            throw new \InvalidArgumentException($label.' must be between '.$minimum.' and '.$maximum.'.');
        }

        return $number;
    }

    private function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
