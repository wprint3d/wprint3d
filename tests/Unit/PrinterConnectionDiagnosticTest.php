<?php

namespace Tests\Unit;

use App\Support\PrinterConnectionDiagnostic;
use Tests\TestCase;

class PrinterConnectionDiagnosticTest extends TestCase
{
    public function test_filter_returns_recent_usb_error_context(): void
    {
        $diagnostic = new PrinterConnectionDiagnostic;

        $output = implode(PHP_EOL, [
            '[66586.170583] usb 3-2: New USB device found, idVendor=2341, idProduct=0010',
            '[66586.174837] cdc_acm 3-2:1.0: ttyACM0: USB ACM device',
            '[73476.224266] usb 3-2: device descriptor read/64, error -71',
            '[73492.234561] usb usb2-port4: attempt power cycle',
        ]);

        $this->assertSame($output, $diagnostic->filter($output, '/dev/ttyACM0'));
    }

    public function test_filter_returns_null_when_usb_context_has_no_error_evidence(): void
    {
        $diagnostic = new PrinterConnectionDiagnostic;

        $this->assertNull($diagnostic->filter(
            '[66586.174837] cdc_acm 3-2:1.0: ttyACM0: USB ACM device',
            '/dev/ttyACM0'
        ));
    }
}
