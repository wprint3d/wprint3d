<?php

$defaultDevelopmentMountPath = is_dir('/var/www/plugins-dev')
    ? '/var/www/plugins-dev'
    : base_path('examples/plugins');
$defaultDevelopmentMountPaths = array_values(array_unique(array_filter([
    $defaultDevelopmentMountPath,
])));

return [
    'sdk_version' => 1,
    'sdk_revision' => 4,

    'sdk' => [
        'current' => [
            'version' => 1,
            'revision' => 4,
        ],
        'versions' => [
            1 => [
                'label' => 'WPrint3D Plugin SDK 1',
                'status' => 'active',
                'defaultRevision' => 4,
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
                ],
            ],
        ],
    ],

    'core_version' => env('APP_VERSION', '0.0.0'),

    'paths' => [
        'root' => storage_path('app/plugins'),
        'packages' => storage_path('app/plugins/packages'),
        'runtime' => storage_path('app/plugins/runtime'),
        'tmp' => storage_path('app/plugins/tmp'),
        'examples' => base_path('examples/plugins'),
    ],

    'runtime' => [
        'timeout_secs' => (int) env('PLUGIN_RUNTIME_TIMEOUT_SECS', 10),
        'bridge_timeout_secs' => (int) env('PLUGIN_BRIDGE_TIMEOUT_SECS', 5),
        'max_payload_bytes' => (int) env('PLUGIN_RUNTIME_MAX_PAYLOAD_BYTES', 262144),
    ],

    'logs' => [
        'max_entries' => (int) env('PLUGIN_LOG_MAX_ENTRIES', 200),
    ],

    'container' => [
        'cli' => env('CONTAINER_CLI', 'podman'),
        'command_timeout_secs' => (int) env('PLUGIN_CONTAINER_COMMAND_TIMEOUT_SECS', 60),
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
