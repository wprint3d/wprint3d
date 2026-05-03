<?php

namespace Tests\Unit;

use Illuminate\Support\Env;
use Tests\TestCase;

class LoggingConfigurationTest extends TestCase
{
    public function test_log_paths_use_runtime_log_directory_when_configured(): void
    {
        $repository = Env::getRepository();
        $previous = env('WPRINT3D_LOG_DIR');

        $repository->set('WPRINT3D_LOG_DIR', '/var/log/wprint3d/app');

        try {
            $logging = require base_path('config/logging.php');

            $this->assertSame('/var/log/wprint3d/app/laravel.log', $logging['channels']['single']['path']);
            $this->assertSame('/var/log/wprint3d/app/gcode-printer.log', $logging['channels']['gcode-printer']['path']);
            $this->assertSame('/var/log/wprint3d/app/serial.log', $logging['channels']['serial']['path']);
            $this->assertSame('/var/log/wprint3d/app/printer-workers-refresh.log', $logging['channels']['printer-workers-refresh']['path']);
        } finally {
            if ($previous === null) {
                $repository->clear('WPRINT3D_LOG_DIR');
            } else {
                $repository->set('WPRINT3D_LOG_DIR', $previous);
            }
        }
    }

    public function test_log_paths_fall_back_to_storage_logs(): void
    {
        $repository = Env::getRepository();
        $previous = env('WPRINT3D_LOG_DIR');

        $repository->clear('WPRINT3D_LOG_DIR');

        try {
            $logging = require base_path('config/logging.php');

            $this->assertSame(storage_path('logs/laravel.log'), $logging['channels']['single']['path']);
            $this->assertSame(storage_path('logs/serial.log'), $logging['channels']['serial']['path']);
        } finally {
            if ($previous === null) {
                $repository->clear('WPRINT3D_LOG_DIR');
            } else {
                $repository->set('WPRINT3D_LOG_DIR', $previous);
            }
        }
    }
}
