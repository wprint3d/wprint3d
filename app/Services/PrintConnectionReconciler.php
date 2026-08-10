<?php

namespace App\Services;

use App\Exceptions\PrintRecoveryRequiredException;
use App\Libraries\Serial;
use App\Models\Configuration;
use App\Models\Printer;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PrintConnectionReconciler
{
    public const POSITION_TOLERANCE_MM = 0.1;

    public const TARGET_TOLERANCE_CELSIUS = 1.0;

    public const MAX_TEMPERATURE_DROP_CELSIUS = 10.0;

    public function __construct(
        private ?Logger $log = null,
        private ?PrintStateTracker $stateTracker = null
    ) {}

    public function reconcile(Printer $printer, Serial $serial, array $checkpoint): string
    {
        $observed = $this->observe($printer, $serial, $checkpoint);
        $position = $observed['position'];
        $liveStatistics = $observed['statistics'];
        $pending = $checkpoint['pending'] ?? null;
        $matchesBefore = null;
        $matchesAfter = null;
        $affected = [
            'position' => [],
            'extruders' => [],
            'bed' => false,
        ];

        try {
            if (! is_array($pending)) {
                $state = $checkpoint['state'] ?? [];
                $this->assertThermalSafety([$state], $liveStatistics);
                $this->assertPositionMatches($state['position'] ?? [], $position);

                return 'continue';
            }

            $beforeState = $pending['beforeState'] ?? null;
            $afterState = $pending['afterState'] ?? null;

            if (! is_array($beforeState) || ! is_array($afterState)) {
                throw new PrintRecoveryRequiredException(
                    'The pending print checkpoint is incomplete.',
                    identityValidated: true
                );
            }

            $affected = $this->affectedPhysicalFields($beforeState, $afterState);
            $this->assertThermalSafety([$beforeState, $afterState], $liveStatistics);
            $this->assertInvariantPosition($beforeState, $afterState, $position, $affected['position']);

            $classification = $pending['classification'] ?? null;

            if ($classification === PrintStateTracker::CLASSIFICATION_IDEMPOTENT) {
                return 'resend';
            }

            if (
                $classification !== PrintStateTracker::CLASSIFICATION_OBSERVABLE
                || ! $this->hasAffectedPhysicalFields($affected)
            ) {
                throw new PrintRecoveryRequiredException(
                    'The pending printer command has an ambiguous physical outcome.',
                    identityValidated: true
                );
            }

            $matchesBefore = $this->affectedStateMatches(
                $beforeState,
                $position,
                $liveStatistics,
                $affected
            );
            $matchesAfter = $this->affectedStateMatches(
                $afterState,
                $position,
                $liveStatistics,
                $affected
            );

            if ($matchesAfter && ! $matchesBefore) {
                return 'executed';
            }

            if ($matchesBefore && ! $matchesAfter) {
                return 'resend';
            }

            throw new PrintRecoveryRequiredException(
                'The pending printer command has an ambiguous physical outcome.',
                identityValidated: true
            );
        } catch (PrintRecoveryRequiredException $exception) {
            $this->logRecoveryDiagnostics(
                printer: $printer,
                checkpoint: $checkpoint,
                position: $position,
                statistics: $liveStatistics,
                affected: $affected,
                matchesBefore: $matchesBefore,
                matchesAfter: $matchesAfter,
                reason: $exception->getMessage()
            );

            throw $exception;
        }
    }

    public function observe(Printer $printer, Serial $serial, array $checkpoint): array
    {
        $maxRetries = max(0, (int) Configuration::get('negotiationMaxRetries'));
        $timeout = max(1, (int) Configuration::get('negotiationTimeoutSecs'));
        $lastException = null;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                $firmware = $serial->query('M115', timeout: $timeout);
                $this->assertIdentity($printer, $firmware);

                $serial->query('M400', timeout: $timeout);
                $position = movementToXYZE($serial->query('M114', timeout: $timeout));

                $extruderIndexes = array_keys(
                    ($checkpoint['state']['thermal']['extruders'] ?? [])
                    + ($checkpoint['pending']['beforeState']['thermal']['extruders'] ?? [])
                    + ($checkpoint['pending']['afterState']['thermal']['extruders'] ?? [])
                );

                if ($extruderIndexes === []) {
                    $extruderIndexes = [0];
                }

                foreach ($extruderIndexes as $index) {
                    $command = (int) $index === 0 ? 'M105' : 'M105 T'.(int) $index;
                    $printer->setStatistics($serial->query($command, timeout: $timeout), (int) $index);
                }

                return [
                    'position' => $position,
                    'statistics' => $printer->getStatistics(),
                ];
            } catch (PrintRecoveryRequiredException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                $lastException = $exception;
            }
        }

        throw new PrintRecoveryRequiredException(
            'Failed to renegotiate the printer connection after the configured attempts: '.
                ($lastException?->getMessage() ?? 'unknown error')
        );
    }

    private function assertIdentity(Printer $printer, string $firmware): void
    {
        preg_match('/(?:^|\s)UUID:([^\s]+)/i', $firmware, $matches);
        $liveUuid = $matches[1] ?? null;
        $savedUuid = Str::before((string) ($printer->machine['uuid'] ?? ''), '/');

        if ($liveUuid === null || $savedUuid === '' || $liveUuid !== $savedUuid) {
            throw new PrintRecoveryRequiredException('The serial device is not the same printer.');
        }
    }

    private function assertPositionMatches(array $expected, array $actual): void
    {
        foreach (['x', 'y', 'z', 'e'] as $axis) {
            if (! $this->positionValueMatches($expected[$axis] ?? null, $actual[$axis] ?? null)) {
                throw new PrintRecoveryRequiredException(
                    "The printer {$axis}-axis position no longer matches the active print checkpoint.",
                    identityValidated: true
                );
            }
        }
    }

    private function assertInvariantPosition(
        array $beforeState,
        array $afterState,
        array $actual,
        array $affectedAxes
    ): void {
        foreach (['x', 'y', 'z', 'e'] as $axis) {
            if (in_array($axis, $affectedAxes, true)) {
                continue;
            }

            $before = $beforeState['position'][$axis] ?? null;
            $after = $afterState['position'][$axis] ?? null;

            if (
                ! $this->positionValueMatches($before, $after)
                || ! $this->positionValueMatches($before, $actual[$axis] ?? null)
            ) {
                throw new PrintRecoveryRequiredException(
                    "The unaffected printer {$axis}-axis moved during connection recovery.",
                    identityValidated: true
                );
            }
        }
    }

    private function assertThermalSafety(array $states, array $statistics): void
    {
        $extruderIndexes = [];

        foreach ($states as $state) {
            $extruderIndexes = array_values(array_unique(array_merge(
                $extruderIndexes,
                array_keys($state['thermal']['extruders'] ?? [])
            )));
        }

        foreach ($extruderIndexes as $index) {
            $expected = array_values(array_filter(array_map(
                static fn (array $state): mixed => $state['thermal']['extruders'][$index] ?? null,
                $states
            ), 'is_array'));

            $this->assertHeaterSafety(
                "extruder {$index}",
                $expected,
                $statistics['extruders'][$index] ?? null
            );
        }

        $expectedBed = array_values(array_filter(array_map(
            static fn (array $state): mixed => $state['thermal']['bed'] ?? null,
            $states
        ), 'is_array'));

        if ($expectedBed !== []) {
            $this->assertHeaterSafety('bed', $expectedBed, $statistics['bed'] ?? null);
        }
    }

    private function assertHeaterSafety(string $label, array $expectedStates, mixed $actual): void
    {
        if (! is_array($actual)) {
            throw new PrintRecoveryRequiredException(
                "The {$label} state is unavailable after connection recovery.",
                identityValidated: true
            );
        }

        $actualTarget = $actual['target'] ?? null;

        if (! is_numeric($actualTarget)) {
            throw new PrintRecoveryRequiredException(
                "The {$label} target is unavailable after connection recovery.",
                identityValidated: true
            );
        }

        $matchingStates = array_values(array_filter(
            $expectedStates,
            fn (array $expected): bool => $this->heaterTargetMatches($expected, $actual)
        ));

        if ($matchingStates === []) {
            throw new PrintRecoveryRequiredException(
                "The {$label} target changed unexpectedly during connection recovery.",
                identityValidated: true
            );
        }

        if ((float) $actualTarget <= 0) {
            return;
        }

        $actualTemperature = $actual['temperature'] ?? null;

        if (! is_numeric($actualTemperature)) {
            throw new PrintRecoveryRequiredException(
                "The {$label} temperature is unavailable after connection recovery.",
                identityValidated: true
            );
        }

        $referenceTemperatures = [];

        foreach ($matchingStates as $expected) {
            if (is_numeric($expected['temperature'] ?? null)) {
                $referenceTemperatures[] = min(
                    (float) $expected['temperature'],
                    (float) $actualTarget
                );
            }
        }

        $safeReference = $referenceTemperatures === []
            ? (float) $actualTarget
            : max($referenceTemperatures);

        if ((float) $actualTemperature < $safeReference - self::MAX_TEMPERATURE_DROP_CELSIUS) {
            throw new PrintRecoveryRequiredException(
                "The {$label} cooled below the safe connection recovery threshold.",
                identityValidated: true
            );
        }
    }

    private function affectedPhysicalFields(array $beforeState, array $afterState): array
    {
        $affected = [
            'position' => [],
            'extruders' => [],
            'bed' => false,
        ];

        foreach (['x', 'y', 'z', 'e'] as $axis) {
            if ($this->valuesDiffer(
                $beforeState['position'][$axis] ?? null,
                $afterState['position'][$axis] ?? null
            )) {
                $affected['position'][] = $axis;
            }
        }

        $extruderIndexes = array_values(array_unique(array_merge(
            array_keys($beforeState['thermal']['extruders'] ?? []),
            array_keys($afterState['thermal']['extruders'] ?? [])
        )));

        foreach ($extruderIndexes as $index) {
            if ($this->valuesDiffer(
                $beforeState['thermal']['extruders'][$index]['target'] ?? null,
                $afterState['thermal']['extruders'][$index]['target'] ?? null
            )) {
                $affected['extruders'][] = (int) $index;
            }
        }

        $affected['bed'] = $this->valuesDiffer(
            $beforeState['thermal']['bed']['target'] ?? null,
            $afterState['thermal']['bed']['target'] ?? null
        );

        return $affected;
    }

    private function hasAffectedPhysicalFields(array $affected): bool
    {
        return $affected['position'] !== []
            || $affected['extruders'] !== []
            || $affected['bed'];
    }

    private function affectedStateMatches(
        array $expected,
        array $position,
        array $statistics,
        array $affected
    ): bool {
        foreach ($affected['position'] as $axis) {
            if (! $this->positionValueMatches(
                $expected['position'][$axis] ?? null,
                $position[$axis] ?? null
            )) {
                return false;
            }
        }

        foreach ($affected['extruders'] as $index) {
            if (! $this->heaterTargetMatches(
                $expected['thermal']['extruders'][$index] ?? [],
                $statistics['extruders'][$index] ?? null
            )) {
                return false;
            }
        }

        return ! $affected['bed'] || $this->heaterTargetMatches(
            $expected['thermal']['bed'] ?? [],
            $statistics['bed'] ?? null
        );
    }

    private function heaterTargetMatches(array $expected, mixed $actual): bool
    {
        if (! is_array($actual)) {
            return false;
        }

        if ($this->targetValueMatches($expected['target'] ?? null, $actual['target'] ?? null)) {
            return true;
        }

        if (($expected['targetConfirmationPending'] ?? false) !== true) {
            return false;
        }

        return $this->stateTracker()->effectiveTargetCanBeConfirmed(
            requestedTarget: $expected['requestedTarget'] ?? $expected['target'] ?? null,
            observedTarget: $actual['target'] ?? null,
            previousTemperature: $expected['temperature'] ?? null,
            observedTemperature: $actual['temperature'] ?? null
        );
    }

    private function stateTracker(): PrintStateTracker
    {
        return $this->stateTracker ??= app(PrintStateTracker::class);
    }

    private function positionValueMatches(mixed $expected, mixed $actual): bool
    {
        return is_numeric($expected)
            && is_numeric($actual)
            && abs((float) $expected - (float) $actual) <= self::POSITION_TOLERANCE_MM;
    }

    private function targetValueMatches(mixed $expected, mixed $actual): bool
    {
        return is_numeric($expected)
            && is_numeric($actual)
            && abs((float) $expected - (float) $actual) <= self::TARGET_TOLERANCE_CELSIUS;
    }

    private function valuesDiffer(mixed $before, mixed $after): bool
    {
        if (is_numeric($before) && is_numeric($after)) {
            return abs((float) $before - (float) $after) > PHP_FLOAT_EPSILON;
        }

        return $before !== $after;
    }

    private function diagnostics(
        Printer $printer,
        array $checkpoint,
        array $position,
        array $statistics,
        array $affected,
        ?bool $matchesBefore,
        ?bool $matchesAfter,
        string $reason
    ): array {
        $pending = $checkpoint['pending'] ?? null;
        $beforeState = is_array($pending) ? ($pending['beforeState'] ?? []) : ($checkpoint['state'] ?? []);
        $afterState = is_array($pending) ? ($pending['afterState'] ?? []) : ($checkpoint['state'] ?? []);
        $positionDiagnostics = [];

        foreach (['x', 'y', 'z', 'e'] as $axis) {
            $before = $beforeState['position'][$axis] ?? null;
            $after = $afterState['position'][$axis] ?? null;
            $actual = $position[$axis] ?? null;
            $positionDiagnostics[$axis] = [
                'affected' => in_array($axis, $affected['position'], true),
                'before' => $before,
                'after' => $after,
                'observed' => $actual,
                'matchesBefore' => $this->positionValueMatches($before, $actual),
                'matchesAfter' => $this->positionValueMatches($after, $actual),
                'beforeDelta' => $this->numericDelta($before, $actual),
                'afterDelta' => $this->numericDelta($after, $actual),
            ];
        }

        return [
            'printerId' => (string) $printer->_id,
            'reason' => $reason,
            'command' => is_array($pending) ? ($pending['command'] ?? null) : null,
            'classification' => is_array($pending) ? ($pending['classification'] ?? null) : null,
            'matchesBefore' => $matchesBefore,
            'matchesAfter' => $matchesAfter,
            'position' => $positionDiagnostics,
            'thermal' => $this->thermalDiagnostics(
                $beforeState['thermal'] ?? [],
                $afterState['thermal'] ?? [],
                $statistics,
                $affected
            ),
        ];
    }

    private function thermalDiagnostics(
        array $before,
        array $after,
        array $actual,
        array $affected
    ): array {
        $extruderIndexes = array_values(array_unique(array_merge(
            array_keys($before['extruders'] ?? []),
            array_keys($after['extruders'] ?? []),
            array_keys($actual['extruders'] ?? [])
        )));
        $extruders = [];

        foreach ($extruderIndexes as $index) {
            $extruders[$index] = $this->heaterDiagnostics(
                $before['extruders'][$index] ?? null,
                $after['extruders'][$index] ?? null,
                $actual['extruders'][$index] ?? null,
                in_array((int) $index, $affected['extruders'], true)
            );
        }

        return [
            'extruders' => $extruders,
            'bed' => $this->heaterDiagnostics(
                $before['bed'] ?? null,
                $after['bed'] ?? null,
                $actual['bed'] ?? null,
                $affected['bed']
            ),
        ];
    }

    private function heaterDiagnostics(
        mixed $before,
        mixed $after,
        mixed $actual,
        bool $affected
    ): array {
        $before = is_array($before) ? $before : [];
        $after = is_array($after) ? $after : [];
        $actual = is_array($actual) ? $actual : [];

        return [
            'affected' => $affected,
            'before' => $before,
            'after' => $after,
            'observed' => $actual,
            'targetMatchesBefore' => $this->targetValueMatches(
                $before['target'] ?? null,
                $actual['target'] ?? null
            ),
            'effectiveTargetMatchesBefore' => $this->heaterTargetMatches($before, $actual),
            'targetMatchesAfter' => $this->targetValueMatches(
                $after['target'] ?? null,
                $actual['target'] ?? null
            ),
            'effectiveTargetMatchesAfter' => $this->heaterTargetMatches($after, $actual),
            'temperatureSafeAgainstBefore' => $this->heaterStateIsSafe($before, $actual),
            'temperatureSafeAgainstAfter' => $this->heaterStateIsSafe($after, $actual),
            'beforeTargetDelta' => $this->numericDelta(
                $before['target'] ?? null,
                $actual['target'] ?? null
            ),
            'afterTargetDelta' => $this->numericDelta(
                $after['target'] ?? null,
                $actual['target'] ?? null
            ),
            'beforeTemperatureDrop' => $this->numericDifference(
                $before['temperature'] ?? null,
                $actual['temperature'] ?? null
            ),
            'afterTemperatureDrop' => $this->numericDifference(
                $after['temperature'] ?? null,
                $actual['temperature'] ?? null
            ),
        ];
    }

    private function heaterStateIsSafe(array $expected, array $actual): bool
    {
        if (! $this->heaterTargetMatches($expected, $actual)) {
            return false;
        }

        $target = (float) $actual['target'];

        if ($target <= 0) {
            return true;
        }

        if (! is_numeric($actual['temperature'] ?? null)) {
            return false;
        }

        $reference = is_numeric($expected['temperature'] ?? null)
            ? min((float) $expected['temperature'], $target)
            : $target;

        return (float) $actual['temperature'] >= $reference - self::MAX_TEMPERATURE_DROP_CELSIUS;
    }

    private function numericDelta(mixed $expected, mixed $actual): ?float
    {
        return is_numeric($expected) && is_numeric($actual)
            ? abs((float) $expected - (float) $actual)
            : null;
    }

    private function numericDifference(mixed $expected, mixed $actual): ?float
    {
        return is_numeric($expected) && is_numeric($actual)
            ? (float) $expected - (float) $actual
            : null;
    }

    private function logRecoveryDiagnostics(
        Printer $printer,
        array $checkpoint,
        array $position,
        array $statistics,
        array $affected,
        ?bool $matchesBefore,
        ?bool $matchesAfter,
        string $reason
    ): void {
        try {
            $this->logger()->warning(
                'Print connection reconciliation requires recovery.',
                $this->diagnostics(
                    printer: $printer,
                    checkpoint: $checkpoint,
                    position: $position,
                    statistics: $statistics,
                    affected: $affected,
                    matchesBefore: $matchesBefore,
                    matchesAfter: $matchesAfter,
                    reason: $reason
                )
            );
        } catch (Throwable) {
            // A logging failure must not replace the physical recovery decision.
        }
    }

    private function logger(): Logger
    {
        return $this->log ??= Log::channel('gcode-printer');
    }
}
