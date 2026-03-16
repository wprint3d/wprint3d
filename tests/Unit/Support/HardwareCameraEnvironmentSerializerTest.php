<?php

namespace Tests\Unit\Support;

use App\Support\HardwareCameraEnvironmentSerializer;
use PHPUnit\Framework\TestCase;

class HardwareCameraEnvironmentSerializerTest extends TestCase
{
    public function test_it_serializes_runtime_fields_for_bash_consumers(): void
    {
        $output = HardwareCameraEnvironmentSerializer::serialize([
            '_id' => 'camera-1',
            'node' => '/dev/video0',
            'enabled' => true,
            'format' => '640x480@30',
            'supportsMjpeg' => false,
            'captureEncoding' => 'YUYV',
            'streamsMjpeg' => true,
        ]);

        $this->assertStringContainsString('_ID="camera-1"', $output);
        $this->assertStringContainsString('NODE="/dev/video0"', $output);
        $this->assertStringContainsString('SUPPORTS_MJPEG="0"', $output);
        $this->assertStringContainsString('CAPTURE_ENCODING="YUYV"', $output);
        $this->assertStringContainsString('STREAMS_MJPEG="1"', $output);
        $this->assertStringContainsString('RESOLUTION="640x480"', $output);
        $this->assertStringContainsString('FRAMERATE="30"', $output);
    }
}
