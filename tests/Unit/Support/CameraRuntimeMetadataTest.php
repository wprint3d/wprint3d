<?php

namespace Tests\Unit\Support;

use App\Support\CameraRuntimeMetadata;
use PHPUnit\Framework\TestCase;

class CameraRuntimeMetadataTest extends TestCase
{
    public function test_it_marks_yuyv_cameras_as_streamable_via_software_encoding(): void
    {
        $metadata = CameraRuntimeMetadata::normalize([
            'format' => '640x480@30',
            'supportsMjpeg' => false,
            'captureEncoding' => 'YUYV',
        ]);

        $this->assertSame('YUYV', $metadata['captureEncoding']);
        $this->assertTrue($metadata['streamsMjpeg']);
        $this->assertSame('640x480', $metadata['resolution']);
        $this->assertSame('30', $metadata['framerate']);
    }

    public function test_it_backfills_legacy_mjpeg_camera_metadata(): void
    {
        $metadata = CameraRuntimeMetadata::normalize([
            'format' => '1280x720@15',
            'supportsMjpeg' => true,
        ]);

        $this->assertSame('MJPG', $metadata['captureEncoding']);
        $this->assertTrue($metadata['streamsMjpeg']);
        $this->assertSame('1280x720', $metadata['resolution']);
        $this->assertSame('15', $metadata['framerate']);
    }

    public function test_it_marks_libcamera_devices_as_streamable(): void
    {
        $metadata = CameraRuntimeMetadata::normalize([
            'format' => '1280x720@30',
            'supportsMjpeg' => false,
            'requiresLibCamera' => true,
        ]);

        $this->assertTrue($metadata['streamsMjpeg']);
        $this->assertNull($metadata['captureEncoding']);
    }
}
