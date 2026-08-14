<?php

$developerMode = filter_var(env('DEVELOPER_MODE', false), FILTER_VALIDATE_BOOL);

return [
    'replace_queue_work' => false,

    'workers' => 1,

    'pools' => [
        'resolver' => App\Queue\PrinterPoolTopologyResolver::class,
        'refresh_seconds' => (float) env('EFFICIENT_QUEUES_POOL_REFRESH_SECONDS', env('SLEEP', 5)),
    ],

    'fork_safety' => [
        'strict' => true,
        'allowed_socket_targets' => [],
        'sanitizers' => [],
        'maximum_parent_private_delta_mb' => 64,
    ],

    'preload' => [
        'classes' => [],
        'files' => [],
        'preloaders' => [],
    ],

    'process' => [
        'shutdown_grace_seconds' => 0,
        'observer_restart_delay_seconds' => 1,
        'connection_failure_backoff_seconds' => 1,
        'connection_failure_backoff_max_seconds' => 30,
        'connection_failure_reset_seconds' => 30,
        'memory_sample_interval_seconds' => 2,
        'event_max_bytes' => 16384,
    ],

    'telemetry' => [
        'enabled' => $developerMode,
        'load_migrations' => $developerMode,
        'driver' => 'mongodb',
        'connection' => 'mongodb',
        'retention_days' => 7,
        'flush_interval_milliseconds' => 250,
        'batch_size' => 100,
        'exception_message_limit' => 2048,
        'redactor' => null,
        'table_prefix' => 'efficient_queue_',
    ],

    'dashboard' => [
        'enabled' => $developerMode,
        'path' => 'efficient-queues',
        'middleware' => ['web'],
        'poll_milliseconds' => 2000,
    ],
];
