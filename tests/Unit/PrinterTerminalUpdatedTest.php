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
