<?php

$defaultDevelopmentMountPath = is_dir('/var/www/plugins-dev')
    ? '/var/www/plugins-dev'
    : base_path('examples/plugins');
$defaultDevelopmentMountPaths = array_values(array_unique(array_filter([
    $defaultDevelopmentMountPath,
])));

return [
    'sdk_version' => 1,
    'sdk_revision' => 5,

    'sdk' => [
        'current' => [
            'version' => 1,
            'revision' => 5,
        ],
        'versions' => [
            1 => [
                'label' => 'WPrint3D Plugin SDK 1',
                'status' => 'active',
                'defaultRevision' => 5,
                'deprecatedAfter' => null,
                'revisions' => [
                    0 => [
                        'releasedAt' => '2026-03-11',
                        'status' => 'supported',
                        'summary' => 'Initial public plugin SDK release.',
                        'changes' => [
                            'Introduced PHP and bridge runtime adapters.',
                            'Added declarative UI surfaces, WebView mode, and custom bundle mode.',
                            'Added signed, sideloaded, and development-mount installation flows.',
                        ],
                    ],
                    1 => [
                        'releasedAt' => '2026-03-12',
                        'status' => 'supported',
                        'summary' => 'Asset-backed elevated UI revisions and settings-tab parity release.',
                        'changes' => [
                            'Added revisioned SDK metadata with compatibility validation.',
                            'Added host-served plugin assets for WebView and custom bundle extensions.',
                            'Standardized dedicated plugin settings tabs and elevated UI warnings.',
                        ],
                    ],
                    2 => [
                        'releasedAt' => '2026-03-13',
                        'status' => 'supported',
                        'summary' => 'Heavyweight plugin runtime dependencies and interactive scaffolding.',
                        'changes' => [
                            'Added manifest-declared container images with optional service and healthcheck metadata.',
                            'Added host CPU and memory requirement declarations plus install-time warnings.',
                            'Added host-managed bridge service image activation and interactive shape-aware plugin scaffolding.',
                        ],
                    ],
                    3 => [
                        'releasedAt' => '2026-03-14',
                        'status' => 'supported',
                        'summary' => 'OctoPrint-oriented compatibility primitives and browser helper APIs.',
                        'changes' => [
                            'Added manifest-declared plugin settings defaults with persisted host settings APIs.',
                            'Added plugin state retrieval plus send_plugin_message/publish_state effect handling.',
                            'Added a host-served OctoPrint compatibility helper for browser surfaces and active-printer-aware navbar data widgets.',
                        ],
                    ],
                    4 => [
                        'releasedAt' => '2026-03-14',
                        'status' => 'current',
                        'summary' => 'Expanded host component registry for declarative plugin UI.',
                        'changes' => [
                            'Added stable host.* declarative component aliases for common React Native Paper and layout primitives.',
                            'Expanded the host renderer with rows, stacks, surfaces, headings, captions, badges, chip groups, switches, inputs, scroll containers, progress bars, and spacers.',
                            'Added plugin-scoped error boundaries so broken plugin surfaces degrade locally instead of crashing the full app.',
                        ],
                    ],
                    5 => [
                        'releasedAt' => '2026-08-06',
                        'status' => 'current',
                        'summary' => 'Managed service lifecycle, runtime proxy, artifact import, and built-in heavyweight plugin packaging.',
                        'changes' => [
                            'Added resource, storage, security, and bridge authentication declarations for managed services.',
                            'Added host-mediated same-origin runtime proxy and runtime artifact import contracts.',
                            'Added integrity metadata and built-in plugin inventory metadata for heavyweight packages.',
                        ],
                    ],
                ],
            ],
        ],
    ],

    'core_version' => env('APP_VERSION', '1.0.0'),

    'paths' => [
        'root' => storage_path('app/plugins'),
        'packages' => storage_path('app/plugins/packages'),
        'runtime' => storage_path('app/plugins/runtime'),
        'tmp' => storage_path('app/plugins/tmp'),
        'examples' => base_path('examples/plugins'),
        'builtins' => base_path('resources/plugins'),
    ],

    'archive' => [
        'max_entries' => (int) env('PLUGIN_ARCHIVE_MAX_ENTRIES', 2048),
        'max_uncompressed_bytes' => (int) env('PLUGIN_ARCHIVE_MAX_UNCOMPRESSED_BYTES', 262144000),
    ],

    'builtins' => [
        'inventory' => base_path('resources/plugins/builtin/index.json'),
        'entries' => [],
    ],

    'rollout' => [
        // The built-in package is installed by default, but activation remains
        // an explicit rollout decision until the release candidate is approved.
        'builtin_cura_enabled' => filter_var(env('WPRINT3D_BUILTIN_CURA_ENABLED', true), FILTER_VALIDATE_BOOL),
        'builtin_cura_auto_enable' => filter_var(env('WPRINT3D_BUILTIN_CURA_AUTO_ENABLE', false), FILTER_VALIDATE_BOOL),
        'runtime_proxy_enabled' => filter_var(env('WPRINT3D_PLUGIN_RUNTIME_PROXY_ENABLED', true), FILTER_VALIDATE_BOOL),
        'runtime_reconcile_enabled' => filter_var(env('WPRINT3D_PLUGIN_RUNTIME_RECONCILE_ENABLED', true), FILTER_VALIDATE_BOOL),
    ],

    'runtime' => [
        'timeout_secs' => (int) env('PLUGIN_RUNTIME_TIMEOUT_SECS', 10),
        'bridge_timeout_secs' => (int) env('PLUGIN_BRIDGE_TIMEOUT_SECS', 5),
        'healthcheck_retries' => (int) env('PLUGIN_HEALTHCHECK_RETRIES', 10),
        'healthcheck_delay_ms' => (int) env('PLUGIN_HEALTHCHECK_DELAY_MS', 250),
        'blue_green_updates' => filter_var(env('PLUGIN_BLUE_GREEN_UPDATES', true), FILTER_VALIDATE_BOOL),
        'max_payload_bytes' => (int) env('PLUGIN_RUNTIME_MAX_PAYLOAD_BYTES', 262144),
        'max_upload_payload_bytes' => (int) env('PLUGIN_RUNTIME_MAX_UPLOAD_PAYLOAD_BYTES', 268435456),
        'max_artifact_import_bytes' => (int) env('PLUGIN_RUNTIME_MAX_ARTIFACT_IMPORT_BYTES', 268435456),
        'proxy_timeout_secs' => (int) env('PLUGIN_RUNTIME_PROXY_TIMEOUT_SECS', 30),
        'max_proxy_timeout_secs' => (int) env('PLUGIN_RUNTIME_MAX_PROXY_TIMEOUT_SECS', 300),
        'max_proxy_stream_timeout_secs' => (int) env('PLUGIN_RUNTIME_MAX_PROXY_STREAM_TIMEOUT_SECS', 1800),
        'proxy_rate_limits' => [
            'metadata_per_minute' => (int) env('PLUGIN_RUNTIME_METADATA_RATE_LIMIT', 120),
            'upload_per_minute' => (int) env('PLUGIN_RUNTIME_UPLOAD_RATE_LIMIT', 10),
            'artifact_per_minute' => (int) env('PLUGIN_RUNTIME_ARTIFACT_RATE_LIMIT', 30),
        ],
        'runtime_token_key' => env('PLUGIN_RUNTIME_TOKEN_KEY'),
    ],

    'logs' => [
        'max_entries' => (int) env('PLUGIN_LOG_MAX_ENTRIES', 200),
    ],

    'container' => [
        'cli' => env('CONTAINER_CLI', 'docker'),
        'command_timeout_secs' => (int) env('PLUGIN_CONTAINER_COMMAND_TIMEOUT_SECS', 60),
        'default_memory_mb' => (int) env('PLUGIN_CONTAINER_DEFAULT_MEMORY_MB', 2048),
        'default_cpu_quota' => (int) env('PLUGIN_CONTAINER_DEFAULT_CPU_QUOTA', 100000),
        'default_pids_limit' => (int) env('PLUGIN_CONTAINER_DEFAULT_PIDS_LIMIT', 256),
        'max_tmpfs_mb' => (int) env('PLUGIN_CONTAINER_MAX_TMPFS_MB', 4096),
        'pull_timeout_secs' => (int) env('PLUGIN_CONTAINER_PULL_TIMEOUT_SECS', 300),
        'max_pull_timeout_secs' => (int) env('PLUGIN_CONTAINER_MAX_PULL_TIMEOUT_SECS', 1800),
        'max_memory_mb' => (int) env('PLUGIN_CONTAINER_MAX_MEMORY_MB', 16384),
        'max_cpu_quota' => (int) env('PLUGIN_CONTAINER_MAX_CPU_QUOTA', 1600000),
        'max_pids' => (int) env('PLUGIN_CONTAINER_MAX_PIDS', 4096),
        'allowed_cap_drops' => ['ALL'],
        'healthcheck_timeout_secs' => (int) env('PLUGIN_CONTAINER_HEALTHCHECK_TIMEOUT_SECS', 30),
        'stop_grace_secs' => (int) env('PLUGIN_CONTAINER_STOP_GRACE_SECS', 30),
        'network' => env('PLUGIN_CONTAINER_NETWORK'),
    ],

    'registry' => [
        'index_url' => env('PLUGIN_REGISTRY_INDEX_URL', 'https://raw.githubusercontent.com/wprint3d/plugin-registry/main/index.json'),
        'website_url' => env('PLUGIN_REGISTRY_WEBSITE_URL', 'https://github.com/wprint3d/plugin-registry'),
        'github_repo' => env('PLUGIN_REGISTRY_GITHUB_REPO', 'wprint3d/plugin-registry'),
        'trusted_keys_index_path' => env('PLUGIN_REGISTRY_TRUSTED_KEYS_INDEX_PATH', 'signers/index.json'),
    ],

    'signature' => [
        'private_key_path' => env('PLUGIN_SIGNING_PRIVATE_KEY'),
        'private_key_passphrase' => env('PLUGIN_SIGNING_PRIVATE_KEY_PASSPHRASE'),
        'trusted_public_keys' => array_filter(array_map('trim', explode(',', (string) env('PLUGIN_TRUSTED_PUBLIC_KEYS', '')))),
        'synced_trusted_keys_path' => env('PLUGIN_SYNCED_TRUSTED_KEYS_PATH', storage_path('app/plugins/trusted-keys')),
    ],

    'development' => [
        'enabled' => filter_var(env('DEVELOPER_MODE', false), FILTER_VALIDATE_BOOL),
        'mount_path' => env('PLUGIN_DEVELOPMENT_MOUNT_PATH', $defaultDevelopmentMountPath),
        'mount_paths' => array_values(array_unique(array_filter(array_map(
            'trim',
            explode(',', (string) env('PLUGIN_DEVELOPMENT_MOUNT_PATHS', implode(',', $defaultDevelopmentMountPaths)))
        )))),
    ],

    'permissions' => [
        'printer.read',
        'printer.command.queue',
        'camera.read',
        'host.metrics.read',
        'network.outbound',
        'storage.read',
        'storage.write',
        'ui.settings_tab',
        'ui.navbar_widget',
        'ui.printer_panel',
        'ui.printer_action',
        'ui.modal',
        'ui.page',
        'ui.webview',
        'ui.custom_bundle',
    ],

    'hooks' => [
        'app.boot',
        'serial.command.before_send',
        'serial.command.response_received',
        'serial.line.received',
        'camera.snapshot.before_take',
        'camera.snapshot.after_take',
        'print.job.started',
        'print.job.failed',
        'print.job.finished',
    ],

    'app_boot' => [
        'dispatch_on_http' => filter_var(env('PLUGIN_APP_BOOT_ON_HTTP', false), FILTER_VALIDATE_BOOL),
    ],

    'ui' => [
        'surfaces' => [
            'settings_tab',
            'navbar_widget',
            'printer_panel',
            'printer_action',
            'modal',
            'page',
        ],
        'modes' => [
            'declarative',
            'webview',
            'custom_bundle',
        ],
    ],
];
