<?php

namespace Tests\Unit;

use App\Events\PrinterConnectionStatusUpdated;
use App\Events\PrinterTerminalUpdated;
use App\Models\Printer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PrinterTerminalUpdatedTest extends TestCase
{
    public function test_temperature_report_without_ok_broadcasts_fresh_statistics(): void
    {
        Event::fake([PrinterConnectionStatusUpdated::class]);

        $printerId = 'printer-temperature-auto-report-test';
        $temperatureReport = 'T:205.70 /230.00 B:69.99 /70.00 @:127 B@:54 W:?';

        $this->assertTrue(Printer::setStatisticsOf($printerId, $temperatureReport, 0));

        new PrinterTerminalUpdated(
            printerId: $printerId,
            command: $temperatureReport,
            thresholdSecs: 7
        );

        $this->assertSame([
            'extruders' => [
                [
                    'temperature' => 205.7,
                    'target' => 230.0,
                ],
            ],
            'bed' => [
                'temperature' => 69.99,
                'target' => 70.0,
            ],
        ], Printer::getStatisticsOf($printerId));

        Event::assertDispatched(
            PrinterConnectionStatusUpdated::class,
            fn (PrinterConnectionStatusUpdated $event): bool => $event->printerId === $printerId
                && $event->statistics === Printer::getStatisticsOf($printerId)
        );
    }

    public function test_temperature_detection_ignores_embedded_terminal_text(): void
    {
        $this->assertFalse(Printer::hasTemperatureReport('echo: target T:230.00'));
        $this->assertFalse(Printer::hasTemperatureReport('echo:enqueueing "M105"'));
        $this->assertTrue(Printer::hasTemperatureReport('ok T:24.00 /0.00 B:24.00 /0.00'));
        $this->assertTrue(Printer::hasTemperatureReport('T:24.00 /0.00 B:24.00 /0.00'));
    }

    public function test_busy_keepalive_recovers_an_unresponsive_connection(): void
    {
        Event::fake([PrinterConnectionStatusUpdated::class]);

        $printerId = 'printer-busy-keepalive-test';
        $previousLastSeen = time() - 30;

        Cache::put($printerId.Printer::CACHE_LAST_SEEN_SUFFIX, $previousLastSeen);
        Printer::setConnectionStatusOf(
            $printerId,
            Printer::CONNECTION_STATUS_UNRESPONSIVE,
            '[73476.224266] usb 3-2: device descriptor read/64, error -71'
        );

        new PrinterTerminalUpdated(
            printerId: $printerId,
            command: 'echo:busy: processing',
            thresholdSecs: 7
        );

        $this->assertSame(
            Printer::CONNECTION_STATUS_ONLINE,
            Printer::getConnectionStatusOf($printerId)
        );
        $this->assertNull(Printer::getConnectionDiagnosticOf($printerId));
        $this->assertGreaterThan($previousLastSeen, Printer::getLastSeenOf($printerId));

        Event::assertDispatched(
            PrinterConnectionStatusUpdated::class,
            fn (PrinterConnectionStatusUpdated $event): bool => $event->printerId === $printerId
                && $event->connectionStatus === Printer::CONNECTION_STATUS_ONLINE
                && $event->connectionDiagnostic === null
        );
    }
}
