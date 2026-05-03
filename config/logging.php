<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;

$wprint3dLogPath = function (string $filename): string {
    $logDir = env('WPRINT3D_LOG_DIR');

    if ($logDir) {
        return rtrim($logDir, '/').'/'.$filename;
    }

    return storage_path('logs/'.$filename);
};

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that gets used when writing
    | messages to the logs. The name specified in this option should match
    | one of the channels defined in the "channels" configuration array.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Out of
    | the box, Laravel uses the Monolog PHP logging library. This gives
    | you a variety of powerful log handlers / formatters to utilize.
    |
    | Available Drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog",
    |                    "custom", "stack"
    |
    */

    'channels' => [
        'deprecations' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'stack' => [
            'driver' => 'stack',
            'channels' => ['single'],
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'daily',
            'path' => $wprint3dLogPath('laravel.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 1,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => $wprint3dLogPath('laravel.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 14,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => 'Laravel Log',
            'emoji' => ':boom:',
            'level' => env('LOG_LEVEL', 'critical'),
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'info'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'info'),
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => [
                'stream' => 'php://stderr',
            ],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'info'),
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'info'),
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => $wprint3dLogPath('laravel.log'),
        ],

        'jobs-reset' => [
            'driver' => 'daily',
            'path' => $wprint3dLogPath('jobs-reset.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 1,
        ],

        'serial-mapper' => [
            'driver' => 'daily',
            'path' => $wprint3dLogPath('serial-mapper.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 1,
        ],

        'hardware-cameras-mapper' => [
            'driver' => 'daily',
            'path' => $wprint3dLogPath('hardware-cameras-mapper.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 1,
        ],

        'gcode-printer' => [
            'driver' => 'daily',
            'path' => $wprint3dLogPath('gcode-printer.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 1,
        ],

        'printers-poller' => [
            'driver' => 'daily',
            'path' => $wprint3dLogPath('printers-poller.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 1,
        ],

        'serial' => [
            'driver' => 'daily',
            'path' => $wprint3dLogPath('serial.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 1,
        ],

        'video-renderer' => [
            'driver' => 'daily',
            'path' => $wprint3dLogPath('video-renderer.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 1,
        ],

        'queued-commands-listener' => [
            'driver' => 'daily',
            'path' => $wprint3dLogPath('queued-commands-listener.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 1,
        ],

        'package-manager' => [
            'driver' => 'daily',
            'path' => $wprint3dLogPath('package-manager.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 1,
        ],

        'concurrent-runner' => [
            'driver' => 'daily',
            'path' => $wprint3dLogPath('concurrent-runner.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 1,
        ],

        'printer-workers-refresh' => [
            'driver' => 'daily',
            'path' => $wprint3dLogPath('printer-workers-refresh.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 1,
        ],
    ],

];
