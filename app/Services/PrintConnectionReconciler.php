<?php

namespace App\Services;

use App\Exceptions\PrintRecoveryRequiredException;
use App\Libraries\Serial;
use App\Models\Configuration;
use App\Models\Printer;
use Illuminate\Support\Str;
use Throwable;

class PrintConnectionReconciler
{
    public const POSITION_TOLERANCE_MM = 0.1;

    public const TARGET_TOLERANCE_CELSIUS = 1.0;

    public const MAX_TEMPERATURE_DROP_CELSIUS = 10.0;

    public function reconcile(Printer $printer, Serial $serial, array $checkpoint): string
    {
        $observed = $this->observe($printer, $serial, $checkpoint);
        $position = $observed['position'];
        $liveStatistics = $observed['statistics'];
        $pending = $checkpoint['pending'] ?? null;

        if (! is_array($pending)) {
            $this->assertStateMatches($checkpoint['state'], $position, $liveStatistics);

            return 'continue';
        }

        $matchesBefore = $this->stateMatches($pending['beforeState'], $position, $liveStatistics);
        $matchesAfter = $this->stateMatches($pending['afterState'], $position, $liveStatistics);

        if ($matchesAfter && ! $matchesBefore) {
            return 'executed';
        }

        if ($matchesBefore && ! $matchesAfter) {
            return 'resend';
        }

        if (
            $matchesBefore
            && $matchesAfter
            && ($pending['classification'] ?? null) === PrintStateTracker::CLASSIFICATION_IDEMPOTENT
        ) {
            return 'resend';
        }

        throw new PrintRecoveryRequiredException(
            'The pending printer command has an ambiguous physical outcome.',
            identityValidated: true
        );
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
                    + ($checkpoint['pending']['afterState']['thermal']['extruders'] ?? [])
                );

                if ($extruderIndexes === []) {
                    $extruderIndexes = [0];
                }

                foreach ($extruderIndexes as $index) {
                    $command = (int) $index === 0 ? 'M105' : 'M105 T'.(int) $index;
                    $printer->setStatistics($serial->query($command, timeout: $timeout), (int) $index);
                }

                $liveStatistics = $printer->getStatistics();

                return [
                    'position' => $position,
                    'statistics' => $liveStatistics,
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

    private function assertStateMatches(array $expected, array $position, array $statistics): void
    {
        if (! $this->stateMatches($expected, $position, $statistics)) {
            throw new PrintRecoveryRequiredException(
                'The printer position or thermal state no longer matches the active print checkpoint.',
                identityValidated: true
            );
        }
    }

    private function stateMatches(array $expected, array $position, array $statistics): bool
    {
        foreach (['x', 'y', 'z', 'e'] as $axis) {
            $expectedValue = $expected['position'][$axis] ?? null;
            $actualValue = $position[$axis] ?? null;

            if (
                ! is_numeric($expectedValue)
                || ! is_numeric($actualValue)
                || abs((float) $expectedValue - (float) $actualValue) > self::POSITION_TOLERANCE_MM
            ) {
                return false;
            }
        }

        foreach (($expected['thermal']['extruders'] ?? []) as $index => $heater) {
            if (! $this->heaterMatches($heater, $statistics['extruders'][$index] ?? null)) {
                return false;
            }
        }

        $expectedBed = $expected['thermal']['bed'] ?? null;

        if ($expectedBed !== null && ! $this->heaterMatches($expectedBed, $statistics['bed'] ?? null)) {
            return false;
        }

        return true;
    }

    private function heaterMatches(array $expected, mixed $actual): bool
    {
        if (! is_array($actual)) {
            return false;
        }

        $expectedTarget = $expected['target'] ?? null;
        $actualTarget = $actual['target'] ?? null;

        if (
            ! is_numeric($expectedTarget)
            || ! is_numeric($actualTarget)
            || abs((float) $expectedTarget - (float) $actualTarget) > self::TARGET_TOLERANCE_CELSIUS
        ) {
            return false;
        }

        if ((float) $expectedTarget <= 0) {
            return true;
        }

        $expectedTemperature = $expected['temperature'] ?? null;
        $actualTemperature = $actual['temperature'] ?? null;

        return is_numeric($expectedTemperature)
            && is_numeric($actualTemperature)
            && (float) $actualTemperature >= (float) $expectedTemperature - self::MAX_TEMPERATURE_DROP_CELSIUS;
    }
}
