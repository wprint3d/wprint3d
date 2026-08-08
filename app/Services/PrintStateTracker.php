<?php

namespace App\Services;

use App\Enums\FormatterCommands;

class PrintStateTracker
{
    public const CLASSIFICATION_OBSERVABLE = 'observable';

    public const CLASSIFICATION_IDEMPOTENT = 'idempotent';

    public const CLASSIFICATION_AMBIGUOUS = 'ambiguous';

    public function initial(array $position, array $statistics): array
    {
        return [
            'position' => [
                'x' => $this->nullableFloat($position['x'] ?? null),
                'y' => $this->nullableFloat($position['y'] ?? null),
                'z' => $this->nullableFloat($position['z'] ?? null),
                'e' => $this->nullableFloat($position['e'] ?? null),
            ],
            'modal' => [
                'movement' => 'G90',
                'extruder' => 'M82',
                'units' => 'G21',
                'tool' => 0,
            ],
            'returnPosition' => null,
            'thermal' => $this->thermalSnapshot($statistics),
        ];
    }

    public function withThermalSnapshot(array $state, array $statistics): array
    {
        $snapshot = $this->thermalSnapshot($statistics);

        foreach ($snapshot['extruders'] as $index => $extruder) {
            $state['thermal']['extruders'][$index] ??= [
                'temperature' => null,
                'target' => null,
            ];
            $state['thermal']['extruders'][$index]['temperature'] = $extruder['temperature'];
            $state['thermal']['extruders'][$index]['target'] ??= $extruder['target'];
        }

        if ($snapshot['bed'] !== null) {
            $state['thermal']['bed'] ??= [
                'temperature' => null,
                'target' => null,
            ];
            $state['thermal']['bed']['temperature'] = $snapshot['bed']['temperature'];
            $state['thermal']['bed']['target'] ??= $snapshot['bed']['target'];
        }

        return $state;
    }

    public function predict(array $state, string $command): array
    {
        $next = $state;
        $command = strtoupper(trim($command));
        $code = strtok($command, " \t") ?: '';
        $parameters = $this->parameters($command);
        $classification = self::CLASSIFICATION_AMBIGUOUS;
        $requiresPositionRefresh = false;

        if (in_array($code, ['G0', 'G1', 'G2', 'G3'], true)) {
            if (str_ends_with($command, ';'.FormatterCommands::IGNORE_POSITION_CHANGE)) {
                $next['returnPosition'] = $state['position'];
            }

            $scale = ($state['modal']['units'] ?? 'G21') === 'G20' ? 25.4 : 1.0;

            foreach (['x', 'y', 'z', 'e'] as $axis) {
                $parameter = strtoupper($axis);

                if (! array_key_exists($parameter, $parameters)) {
                    continue;
                }

                $value = $parameters[$parameter] * $scale;
                $mode = $axis === 'e'
                    ? ($state['modal']['extruder'] ?? 'M82')
                    : ($state['modal']['movement'] ?? 'G90');

                if (in_array($mode, ['G91', 'M83'], true)) {
                    $next['position'][$axis] = ($state['position'][$axis] ?? 0.0) + $value;
                } else {
                    $next['position'][$axis] = $value;
                }
            }

            $classification = $next['position'] === $state['position']
                ? self::CLASSIFICATION_IDEMPOTENT
                : self::CLASSIFICATION_OBSERVABLE;
        } elseif ($code === 'G92') {
            $scale = ($state['modal']['units'] ?? 'G21') === 'G20' ? 25.4 : 1.0;

            foreach (['x', 'y', 'z', 'e'] as $axis) {
                $parameter = strtoupper($axis);

                if (array_key_exists($parameter, $parameters)) {
                    $next['position'][$axis] = $parameters[$parameter] * $scale;
                }
            }

            $classification = $next['position'] === $state['position']
                ? self::CLASSIFICATION_IDEMPOTENT
                : self::CLASSIFICATION_OBSERVABLE;
        } elseif (in_array($code, ['G90', 'G91'], true)) {
            $next['modal']['movement'] = $code;
            $classification = self::CLASSIFICATION_IDEMPOTENT;
        } elseif (in_array($code, ['M82', 'M83'], true)) {
            $next['modal']['extruder'] = $code;
            $classification = self::CLASSIFICATION_IDEMPOTENT;
        } elseif (in_array($code, ['G20', 'G21'], true)) {
            $next['modal']['units'] = $code;
            $classification = self::CLASSIFICATION_IDEMPOTENT;
        } elseif (preg_match('/^T(\d+)$/', $code, $matches)) {
            $next['modal']['tool'] = (int) $matches[1];
            $requiresPositionRefresh = true;
        } elseif (in_array($code, ['M104', 'M109'], true)) {
            $tool = isset($parameters['T'])
                ? (int) $parameters['T']
                : (int) ($state['modal']['tool'] ?? 0);
            $target = $parameters['S'] ?? $parameters['R'] ?? null;

            if ($target !== null) {
                $next['thermal']['extruders'][$tool]['target'] = (float) $target;
                $next['thermal']['extruders'][$tool]['temperature'] ??= null;
                $classification = $next['thermal']['extruders'][$tool]
                    === ($state['thermal']['extruders'][$tool] ?? null)
                    ? self::CLASSIFICATION_IDEMPOTENT
                    : self::CLASSIFICATION_OBSERVABLE;
            }
        } elseif (in_array($code, ['M140', 'M190'], true)) {
            $target = $parameters['S'] ?? $parameters['R'] ?? null;

            if ($target !== null) {
                $next['thermal']['bed']['target'] = (float) $target;
                $next['thermal']['bed']['temperature'] ??= null;
                $classification = $next['thermal']['bed'] === ($state['thermal']['bed'] ?? null)
                    ? self::CLASSIFICATION_IDEMPOTENT
                    : self::CLASSIFICATION_OBSERVABLE;
            }
        } elseif (
            in_array($code, [
                'M105', 'M114', 'M115', 'M400', 'M73', 'M75', 'M77',
                'M106', 'M107', 'M108', 'M84', 'M117', 'M201', 'M203',
                'M204', 'M205', 'M220', 'M221', 'M300', 'M900', 'G4',
            ], true)
        ) {
            $classification = self::CLASSIFICATION_IDEMPOTENT;
        } elseif (str_starts_with($code, 'G')) {
            $requiresPositionRefresh = true;
        }

        return [
            'state' => $next,
            'classification' => $classification,
            'requiresPositionRefresh' => $requiresPositionRefresh,
        ];
    }

    public function thermalSnapshot(array $statistics): array
    {
        $snapshot = [
            'extruders' => [],
            'bed' => null,
        ];

        foreach (($statistics['extruders'] ?? []) as $index => $extruder) {
            $snapshot['extruders'][(int) $index] = [
                'temperature' => $this->nullableFloat($extruder['temperature'] ?? null),
                'target' => $this->nullableFloat($extruder['target'] ?? null),
            ];
        }

        if (isset($statistics['bed']) && is_array($statistics['bed'])) {
            $snapshot['bed'] = [
                'temperature' => $this->nullableFloat($statistics['bed']['temperature'] ?? null),
                'target' => $this->nullableFloat($statistics['bed']['target'] ?? null),
            ];
        }

        return $snapshot;
    }

    private function parameters(string $command): array
    {
        preg_match_all(
            '/(?:^|\s)([A-Z])\s*(-?(?:\d+(?:\.\d*)?|\.\d+))/',
            $command,
            $matches,
            PREG_SET_ORDER
        );

        $parameters = [];

        foreach ($matches as $match) {
            $parameters[$match[1]] = (float) $match[2];
        }

        return $parameters;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
