<?php

namespace Tests\Unit;

use App\Events\PrinterConnectionStatusUpdated;
use App\Models\Printer;

use Illuminate\Support\Facades\Cache;

use Tests\TestCase;

class PrinterConnectionStatusUpdatedTest extends TestCase
{
    public function test_connection_status_event_exposes_preview_reconciliation_fields(): void
    {
        $printerId = 'printer-preview-status-test';

        Cache::put($printerId . Printer::CACHE_CURRENT_LINE_SUFFIX, 42);
        Cache::put($printerId . Printer::CACHE_MAX_LINE_SUFFIX, 420);
        Cache::put($printerId . Printer::CACHE_CURRENT_LAYER_SUFFIX, 3);
        Cache::put($printerId . Printer::CACHE_MAX_LAYER_SUFFIX, 18);
        Cache::put($printerId . Printer::CACHE_ABSOLUTE_POSITION_SUFFIX, [
            'x' => 12.5,
            'y' => 18.0,
            'z' => 0.4,
            'e' => 9.25,
        ]);

        $event = new PrinterConnectionStatusUpdated(
            printerId: $printerId,
            hasActiveFile: true
        );

        $this->assertSame(42, $event->currentLine);
        $this->assertSame(420, $event->maxLine);
        $this->assertSame([
            'x' => 12.5,
            'y' => 18.0,
            'z' => 0.4,
            'e' => 9.25,
        ], $event->absolutePosition);
    }
}
