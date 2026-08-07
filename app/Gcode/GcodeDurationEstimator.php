<?php

namespace App\Gcode;

use RuntimeException;

/**
 * Streaming duration estimator for Marlin, RepRap and Klipper-style printer G-code.
 * Defaults are deliberately conservative and are superseded by limits in the file.
 */
final class GcodeDurationEstimator
{
    private const MAX_SECONDS = 31_536_000;

    private array $position = ['X' => 0.0, 'Y' => 0.0, 'Z' => 0.0, 'E' => 0.0];

    private array $offset = ['X' => 0.0, 'Y' => 0.0, 'Z' => 0.0, 'E' => 0.0];

    private array $maxFeed = ['X' => 300.0, 'Y' => 300.0, 'Z' => 15.0, 'E' => 50.0];

    private array $maxAcceleration = ['X' => 3000.0, 'Y' => 3000.0, 'Z' => 100.0, 'E' => 5000.0];

    private array $acceleration = ['print' => 1000.0, 'travel' => 1500.0, 'retract' => 3000.0];

    private array $retract = ['length' => 2.0, 'feed' => 25.0, 'unretractExtra' => 0.0, 'unretractFeed' => 25.0];

    private float $unit = 1.0;

    private float $feed = 25.0;

    private float $speedFactor = 1.0;

    private array $junction = [
        'minimumPrint' => 0.0,
        'minimumTravel' => 0.0,
        'junctionDeviation' => 0.02,
        'X' => 0.0,
        'Y' => 0.0,
        'Z' => 0.0,
        'E' => 0.0,
    ];

    private bool $absoluteAxes = true;

    private bool $absoluteExtrusion = true;

    private bool $absoluteArcCenter = false;

    private string $plane = 'G17';

    private float $seconds = 0.0;

    private ?int $metadataSeconds = null;

    private bool $hasUnboundedWait = false;

    private int $lineCount = 0;

    public function estimate(string $path): DurationEstimate
    {
        $stream = @fopen($path, 'rb');

        if ($stream === false) {
            throw new RuntimeException("Unable to open G-code file: {$path}");
        }

        try {
            while (($line = fgets($stream)) !== false) {
                $this->lineCount++;
                $this->parseMetadata($line);
                $this->processLine($line);
            }
        } finally {
            fclose($stream);
        }

        $simulated = $this->finiteSeconds($this->seconds);
        $metadata = $this->metadataSeconds;
        $useMetadata = $metadata !== null && ($simulated === 0 || ($metadata >= $simulated * 0.5 && $metadata <= $simulated * 2.0));
        $seconds = $useMetadata ? $metadata : $simulated;

        return new DurationEstimate(
            seconds: $seconds,
            origin: $useMetadata ? 'metadata' : 'analysis',
            metadataSeconds: $metadata,
            simulatedSeconds: $simulated,
            hasUnboundedWait: $this->hasUnboundedWait,
            lineCount: $this->lineCount,
        );
    }

    private function processLine(string $raw): void
    {
        $line = preg_replace('/\([^)]*\)/', '', $raw) ?? '';
        $line = explode(';', $line, 2)[0];
        $line = preg_replace('/^\s*N\d+\s*/i', '', trim($line)) ?? '';
        $line = preg_replace('/\*\d+\s*$/', '', $line) ?? '';

        if (preg_match('/^(?:TEMPERATURE_WAIT|PAUSE)(?:\s|$)/i', trim($line))) {
            $this->hasUnboundedWait = true;

            return;
        }

        if (! preg_match('/^([GMT]\d+(?:\.\d+)?)(?:\s|$)/i', trim($line), $match)) {
            return;
        }

        $command = strtoupper($match[1]);
        $parameters = $this->parameters(substr(trim($line), strlen($match[0])));

        match ($command) {
            'G0', 'G00', 'G1', 'G01' => $this->linear($parameters),
            'G2', 'G02' => $this->arc($parameters, true),
            'G3', 'G03' => $this->arc($parameters, false),
            'G4', 'G04' => $this->dwell($parameters),
            'G10' => $this->firmwareRetract(false),
            'G11' => $this->firmwareRetract(true),
            'G17', 'G18', 'G19' => $this->plane = $command,
            'G20' => $this->unit = 25.4,
            'G21' => $this->unit = 1.0,
            'G90' => $this->absoluteAxes = true,
            'G91' => $this->absoluteAxes = false,
            'G90.1' => $this->absoluteArcCenter = true,
            'G91.1' => $this->absoluteArcCenter = false,
            'G92' => $this->setOffsets($parameters),
            'M0', 'M1', 'M00', 'M01', 'M25', 'M109', 'M190', 'M191', 'M600' => $this->hasUnboundedWait = true,
            'M82' => $this->absoluteExtrusion = true,
            'M83' => $this->absoluteExtrusion = false,
            'M201' => $this->axisSettings($this->maxAcceleration, $parameters),
            'M203' => $this->axisSettings($this->maxFeed, $parameters),
            'M204' => $this->setAcceleration($parameters),
            'M205' => $this->setJunction($parameters),
            'M207' => $this->setRetract($parameters, false),
            'M208' => $this->setRetract($parameters, true),
            'M220' => $this->speedFactor = isset($parameters['S']) ? max(0.01, min(10.0, $parameters['S'] / 100.0)) : $this->speedFactor,
            default => null,
        };
    }

    private function linear(array $p): void
    {
        if (isset($p['F']) && $p['F'] > 0) {
            $this->feed = $p['F'] * $this->unit / 60.0;
        }

        $next = $this->nextPosition($p);
        $delta = $this->delta($next);
        $xyz = hypot(hypot($delta['X'], $delta['Y']), $delta['Z']);
        $distance = $xyz > 0 ? hypot($xyz, $delta['E']) : abs($delta['E']);

        if ($distance > 0) {
            $kind = $xyz === 0.0 ? 'retract' : ($delta['E'] > 0 ? 'print' : 'travel');
            $this->seconds += $this->moveTime($distance, $delta, $this->acceleration[$kind]);
        }

        $this->position = $next;
    }

    private function arc(array $p, bool $clockwise): void
    {
        if (isset($p['F']) && $p['F'] > 0) {
            $this->feed = $p['F'] * $this->unit / 60.0;
        }

        [$a, $b, $helical, $ca, $cb] = match ($this->plane) {
            'G18' => ['X', 'Z', 'Y', 'I', 'K'],
            'G19' => ['Y', 'Z', 'X', 'J', 'K'],
            default => ['X', 'Y', 'Z', 'I', 'J'],
        };
        $next = $this->nextPosition($p);
        $startA = $this->position[$a];
        $startB = $this->position[$b];
        $endA = $next[$a];
        $endB = $next[$b];
        $center = $this->arcCenter($p, $a, $b, $ca, $cb, $startA, $startB, $endA, $endB, $clockwise);

        if ($center === null) {
            $this->linear($p);

            return;
        }

        [$centerA, $centerB] = $center;
        $radius = hypot($startA - $centerA, $startB - $centerB);

        if (! is_finite($radius) || $radius <= 0) {
            $this->position = $next;

            return;
        }

        $startAngle = atan2($startB - $centerB, $startA - $centerA);
        $endAngle = atan2($endB - $centerB, $endA - $centerA);
        $sweep = $clockwise ? $startAngle - $endAngle : $endAngle - $startAngle;
        while ($sweep < 0) {
            $sweep += 2 * M_PI;
        }
        if ($sweep < 1.0e-9 && hypot($endA - $startA, $endB - $startB) < 1.0e-6) {
            $sweep = 2 * M_PI;
        }

        $arcLength = $radius * $sweep;
        $delta = $this->delta($next);
        $distance = hypot(hypot($arcLength, $delta[$helical]), $delta['E']);
        $kind = $delta['E'] > 0 ? 'print' : 'travel';
        $this->seconds += $this->moveTime($distance, $delta, $this->acceleration[$kind]);
        $this->position = $next;
    }

    private function arcCenter(array $p, string $a, string $b, string $ca, string $cb, float $sa, float $sb, float $ea, float $eb, bool $clockwise): ?array
    {
        if (isset($p[$ca]) || isset($p[$cb])) {
            $oa = ($p[$ca] ?? 0.0) * $this->unit;
            $ob = ($p[$cb] ?? 0.0) * $this->unit;

            return $this->absoluteArcCenter
                ? [$oa + $this->offset[$a], $ob + $this->offset[$b]]
                : [$sa + $oa, $sb + $ob];
        }

        if (! isset($p['R'])) {
            return null;
        }

        $r = $p['R'] * $this->unit;
        $chord = hypot($ea - $sa, $eb - $sb);
        if ($chord <= 0 || abs($r) < $chord / 2) {
            return null;
        }
        $midA = ($sa + $ea) / 2;
        $midB = ($sb + $eb) / 2;
        $height = sqrt(max(0.0, ($r * $r) - (($chord * $chord) / 4)));
        $sign = ($clockwise xor $r < 0) ? -1.0 : 1.0;

        return [$midA - (($eb - $sb) / $chord) * $height * $sign, $midB + (($ea - $sa) / $chord) * $height * $sign];
    }

    private function moveTime(float $distance, array $delta, float $acceleration): float
    {
        $velocity = max(0.001, $this->feed * $this->speedFactor);
        foreach ($delta as $axis => $component) {
            if (abs($component) > 0) {
                $velocity = min($velocity, $this->maxFeed[$axis] * $distance / abs($component));
                $acceleration = min($acceleration, $this->maxAcceleration[$axis] * $distance / abs($component));
            }
        }
        $acceleration = max(0.001, $acceleration);
        $rampDistance = ($velocity * $velocity) / $acceleration;

        return $distance >= $rampDistance
            ? (2 * $velocity / $acceleration) + (($distance - $rampDistance) / $velocity)
            : 2 * sqrt($distance / $acceleration);
    }

    private function firmwareRetract(bool $unretract): void
    {
        $distance = $unretract ? $this->retract['length'] + $this->retract['unretractExtra'] : $this->retract['length'];
        $feed = $unretract ? $this->retract['unretractFeed'] : $this->retract['feed'];
        $previousFeed = $this->feed;
        $this->feed = max(0.001, $feed);
        $this->seconds += $this->moveTime($distance, ['X' => 0.0, 'Y' => 0.0, 'Z' => 0.0, 'E' => $distance], $this->acceleration['retract']);
        $this->feed = $previousFeed;
    }

    private function dwell(array $p): void
    {
        $seconds = isset($p['P']) ? $p['P'] / 1000.0 : ($p['S'] ?? 0.0);
        if (is_finite($seconds) && $seconds > 0) {
            $this->seconds = min(self::MAX_SECONDS, $this->seconds + $seconds);
        }
    }

    private function nextPosition(array $p): array
    {
        $next = $this->position;
        foreach (['X', 'Y', 'Z', 'E'] as $axis) {
            if (! isset($p[$axis])) {
                continue;
            }
            $value = $p[$axis] * $this->unit;
            $absolute = $axis === 'E' ? $this->absoluteExtrusion : $this->absoluteAxes;
            $next[$axis] = $absolute ? $value + $this->offset[$axis] : $next[$axis] + $value;
        }

        return $next;
    }

    private function setOffsets(array $p): void
    {
        foreach (['X', 'Y', 'Z', 'E'] as $axis) {
            if (isset($p[$axis])) {
                $this->offset[$axis] = $this->position[$axis] - ($p[$axis] * $this->unit);
            }
        }
    }

    private function setAcceleration(array $p): void
    {
        $print = $p['P'] ?? $p['S'] ?? null;
        if ($print !== null && $print > 0) {
            $this->acceleration['print'] = $print;
        }
        foreach (['T' => 'travel', 'R' => 'retract'] as $key => $kind) {
            if (isset($p[$key]) && $p[$key] > 0) {
                $this->acceleration[$kind] = $p[$key];
            }
        }
    }

    private function setRetract(array $p, bool $unretract): void
    {
        if ($unretract) {
            $this->retract['unretractExtra'] = max(0.0, ($p['S'] ?? 0.0) * $this->unit);
            if (isset($p['F']) && $p['F'] > 0) {
                $this->retract['unretractFeed'] = $p['F'] * $this->unit / 60.0;
            }

            return;
        }
        if (isset($p['S'])) {
            $this->retract['length'] = max(0.0, $p['S'] * $this->unit);
        }
        if (isset($p['F']) && $p['F'] > 0) {
            $this->retract['feed'] = $p['F'] * $this->unit / 60.0;
        }
    }

    private function setJunction(array $p): void
    {
        foreach (['S' => 'minimumPrint', 'T' => 'minimumTravel', 'J' => 'junctionDeviation'] as $key => $setting) {
            if (isset($p[$key]) && $p[$key] >= 0 && is_finite($p[$key])) {
                $this->junction[$setting] = $p[$key] * $this->unit;
            }
        }
        foreach (['X', 'Y', 'Z', 'E'] as $axis) {
            if (isset($p[$axis]) && $p[$axis] >= 0 && is_finite($p[$axis])) {
                $this->junction[$axis] = $p[$axis] * $this->unit;
            }
        }
    }

    private function axisSettings(array &$target, array $p): void
    {
        foreach (['X', 'Y', 'Z', 'E'] as $axis) {
            if (isset($p[$axis]) && $p[$axis] > 0 && is_finite($p[$axis])) {
                $target[$axis] = $p[$axis] * $this->unit;
            }
        }
    }

    private function delta(array $next): array
    {
        $delta = [];
        foreach ($next as $axis => $value) {
            $delta[$axis] = $value - $this->position[$axis];
        }

        return $delta;
    }

    private function parameters(string $line): array
    {
        preg_match_all('/([A-Z])\s*([+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:E[+-]?\d+)?)/i', $line, $matches, PREG_SET_ORDER);
        $parameters = [];
        foreach ($matches as $match) {
            $value = (float) $match[2];
            if (is_finite($value)) {
                $parameters[strtoupper($match[1])] = $value;
            }
        }

        return $parameters;
    }

    private function parseMetadata(string $line): void
    {
        $seconds = null;
        if (preg_match('/^\s*;\s*TIME\s*:\s*(\d+(?:\.\d+)?)/i', $line, $match)) {
            $seconds = (float) $match[1];
        } elseif (preg_match('/^\s*;\s*(?:(?:total )?estimated (?:printing )?time.*?|print[_ ]time)\s*[:=]\s*(.+)$/i', $line, $match)) {
            $seconds = $this->durationString($match[1]);
        }
        if ($seconds !== null && is_finite($seconds) && $seconds > 0 && $seconds <= self::MAX_SECONDS) {
            $this->metadataSeconds = (int) round($seconds);
        }
    }

    private function durationString(string $value): ?float
    {
        if (is_numeric(trim($value))) {
            return (float) trim($value);
        }
        preg_match_all('/(\d+(?:\.\d+)?)\s*(d|h|m|s)\b/i', $value, $matches, PREG_SET_ORDER);
        if ($matches === []) {
            return null;
        }
        $seconds = 0.0;
        foreach ($matches as $match) {
            $seconds += (float) $match[1] * match (strtolower($match[2])) {
                'd' => 86400, 'h' => 3600, 'm' => 60, default => 1
            };
        }

        return $seconds;
    }

    private function finiteSeconds(float $seconds): int
    {
        if (! is_finite($seconds) || $seconds <= 0) {
            return 0;
        }

        return (int) min(self::MAX_SECONDS, ceil($seconds));
    }
}
