<?php

use App\Enums\BackupInterval;
use App\Enums\DataType;

return [
    'defaults' => [

        // System
        'machineUUID' => [
            'value' => null,
            'hint' => 'Machine UUID',
            'type' => DataType::STRING,
            'description' => 'The unique identifier for the machine.',
            'writeable' => false,
            'section' => 'System'
        ],
        'renderFileBlockingSecs' => [
            'value' => 60,
            'hint' => 'Render file blocking seconds',
            'type' => DataType::INTEGER,
            'description' => 'The time in seconds to block rendering recorded video files.',
            'section' => 'System'
        ],
        'showFirstLoginHints' => [
            'value' => true,
            'hint' => 'Show first login hints',
            'type' => DataType::BOOLEAN,
            'description' => 'Whether to show first login hints.',
            'section' => 'System',
            'visible' => false,
            'writeable' => false
        ],
        'checkForUpdates' => [
            'value' => true,
            'hint' => 'Check for updates',
            'type' => DataType::BOOLEAN,
            'description' => 'Whether to check for updates on startup.',
            'section' => 'System'
        ],

        // Connection
        'streamMaxLengthBytes' => [
            'value' => 256,
            'hint' => 'Stream max length bytes',
            'type' => DataType::INTEGER,
            'description' => 'The maximum amount of ASCII characters in a valid G-code command.',
            'section' => 'Connection'
        ],
        'negotiationWaitSecs' => [
            'value' => 7,
            'hint' => 'Negotiation delay',
            'type' => DataType::INTEGER,
            'description' => 'The absolute time (in seconds) that will be spent waiting for the printer to boot before trying to negotiate a connection.',
            'section' => 'Connection'
        ],
        'negotiationTimeoutSecs' => [
            'value' => 3,
            'hint' => 'Negotiation timeout',
            'type' => DataType::INTEGER,
            'description' => 'The maximum time (in seconds) that will be spent setting up a printer, exceeding this time will get the printer invalidated and disabled.',
            'section' => 'Connection'
        ],
        'negotiationMaxRetries' => [
            'value' => 3,
            'hint' => 'Negotiation retry limit',
            'type' => DataType::INTEGER,
            'description' => 'The maximum amount of times that we\'ll try to set up a printer, exceeding this value will get the printer invalidated and disabled.',
            'section' => 'Connection'
        ],
        'printReconnectionGraceSecs' => [
            'value' => 30,
            'hint' => 'Active print reconnection grace period',
            'type' => DataType::INTEGER,
            'description' => 'The maximum time (in seconds) that an active print will wait for its serial device to reappear before entering recovery.',
            'section' => 'Connection'
        ],
        'commandTimeoutSecs' => [
            'value' => 60,
            'hint' => 'Command timeout',
            'type' => DataType::INTEGER,
            'description' => 'The maximum time (in seconds) that will be spent waiting for a response from the printer. Exceeding this time twice will cause the print job to be aborted.',
            'section' => 'Connection'
        ],
        'runningTimeoutSecs' => [
            'value' => 10,
            'hint' => 'Busy check timeout',
            'type' => DataType::INTEGER,
            'description' => 'Some printers will stop responding after leaving the busy state. This is the maximum time (in seconds) that the printer would remain idle before we try forcing a command into it.',
            'section' => 'Connection'
        ],
        'lastSeenThresholdSecs' => [
            'value' => 7,
            'hint' => 'Last seen threshold',
            'type' => DataType::INTEGER,
            'description' => 'This is the interval (in seconds) in that the printer should produce some kind of output. If exceeded, it\'ll be marked as offline.',
            'section' => 'Connection'
        ],
        'lastSeenPollIntervalSecs' => [
            'value' => 5,
            'hint' => 'Last seen poll interval',
            'type' => DataType::INTEGER,
            'description' => 'This is the interval (in seconds) in which the printer will be queried for a response.',
            'section' => 'Connection'
        ],
        'autoSerialIntervalSecs' => [
            'value' => 2,
            'hint' => 'Automatic poll interval',
            'type' => DataType::INTEGER,
            'description' => 'This is the interval (in seconds) in which idling printers will be reached for various automated polling operations, such as temperature, power supply status, firmware details, etc.',
            'section' => 'Connection'
        ],

        // Limits
        'controlDistanceDefault' => [
            'value' => 10,
            'hint' => 'Default travel distance',
            'type' => DataType::INTEGER,
            'description' => 'This is the default distance to move each axis (in mm) that the printer will move from the Control tab.',
            'section' => 'Limits'
        ],
        'controlDistanceMin' => [
            'value' => 1,
            'hint' => 'Minimum travel distance',
            'type' => DataType::INTEGER,
            'description' => 'This is the minimum distance to move each axis (in mm) that the printer will be allowed to move from the Control tab.',
            'section' => 'Limits'
        ],
        'controlDistanceMax' => [
            'value' => 100,
            'hint' => 'Maximum travel distance',
            'type' => DataType::INTEGER,
            'description' => 'This is the maximum distance to move each axis (in mm) that the printer will be allowed to move from the Control tab.',
            'section' => 'Limits'
        ],
        'controlFeedrateDefault' => [
            'value' => 1500,
            'hint' => 'Default feedrate speed',
            'type' => DataType::INTEGER,
            'description' => 'This is the default speed to move each axis (in mm/s) that the printer will move from the Control tab.',
            'section' => 'Limits'
        ],
        'controlFeedrateMin' => [
            'value' => 500,
            'hint' => 'Minimum feedrate speed',
            'type' => DataType::INTEGER,
            'description' => 'This is the minimum speed to move each axis (in mm/s) that the printer will be allowed to move from the Control tab.',
            'section' => 'Limits'
        ],
        'controlFeedrateMax' => [
            'value' => 10000,
            'hint' => 'Maximum feedrate speed',
            'type' => DataType::INTEGER,
            'description' => 'This is the maximum speed to move each axis (in mm/s) that the printer will be allowed to move from the Control tab.',
            'section' => 'Limits'
        ],
        'controlExtrusionFeedrate' => [
            'value' => 50,
            'hint' => 'Extrusion feedrate',
            'type' => DataType::INTEGER,
            'description' => 'This is the absolute speed to extrude material when requested from the Control tab.',
            'section' => 'Limits'
        ],
        'controlExtrusionMinTemp' => [
            'value' => 170,
            'hint' => 'Minimum temperature to extrude',
            'type' => DataType::INTEGER,
            'description' => 'This is the minimum temperature required before being able to extrude material from the Control tab (avoids physical damage to the printer due to cold extrusion).',
            'section' => 'Limits'
        ],

        // Miscellaneous
        'jobBackupInterval' => [
            'enum' => 'BackupInterval',
            'value' => BackupInterval::EVERY_SECOND,
            'hint' => 'Backup interval',
            'type' => DataType::ENUM,
            'description' => 'Specifies how frequently should we try to backup the currently active job.',
            'section' => 'Miscellaneous'
        ],
        'jobStatisticsQueryIntervalSecs' => [
            'value' => 10,
            'hint' => 'Statistics query interval',
            'type' => DataType::INTEGER,
            'description' => 'This is the absolute interval (in seconds) in which the printer statistics will be queried throughout an active print job.',
            'section' => 'Miscellaneous'
        ],
        'terminalMaxLines' => [
            'value' => 512,
            'hint' => 'Maximum terminal lines',
            'type' => DataType::INTEGER,
            'description' => 'This is the maximum amount of lines that can be shown in the Terminal tab. If exceeded, extra lines are removed from oldest to newest.',
            'section' => 'Miscellaneous'
        ],
        'enableHaptics' => [
            'value' => true,
            'hint' => 'Haptic feedback',
            'type' => DataType::BOOLEAN,
            'description' => 'Whether to enable haptic feedback throughout the entire system. Please note that, on mobile devices, it\'ll be necessary to disable the do not disturb mode before this settings has any effect.',
            'section' => 'Miscellaneous'
        ],

        // Advanced settings
        'debugSerial' => [
            'value' => false,
            'hint' => 'Debug serial transactions',
            'type' => DataType::BOOLEAN,
            'description' => 'Whether to enable debug logging of all kinds of transactions running through the serial protocol. This is EXTREMELY taxing for your system\'s I/O throughput and has the potential to cause slowdowns, crashes and timeouts of your print jobs on slower systems such as single-board computers.',
            'section' => 'Advanced settings'
        ],
        'enableLibCamera' => [
            'value' => true,
            'hint' => 'Libcamera support',
            'type' => DataType::BOOLEAN,
            'description' => 'Whether to enable libcamera support. Generally, you\'ll want this setting enabled, however, some single-board computers\' kernels are broken and make the streaming process relatively taxing on the already scarce system resources. This setting lets you disable this feature in favor of using a USB camera or no camera at all instead.',
            'section' => 'Advanced settings'
        ],
        'jobRestorationHomingTemperature' => [
            'value' => 180,
            'hint' => 'Job restore homing temperature',
            'type' => DataType::INTEGER,
            'description' => 'The absolute temperature (in celsius) that is required to cool back down to before moving back home after tapping Recover on a failed job. This avoids leaving hot plastic pieces all over the print and the bed.',
            'section' => 'Advanced settings'
        ],
        'developerMode' => [
            'value' => false,
            'hint' => 'Enable developer mode',
            'type' => DataType::BOOLEAN,
            'description' => 'Whether to enable the developer mode which shows a Development tab with tools for core and plugin developers. A page reload is required to apply changes to this setting.',
            'section' => 'Advanced settings'
        ],
        'fakeSerialEnabled' => [
            'value' => false,
            'hint' => 'Enable FakeSerial printer',
            'type' => DataType::BOOLEAN,
            'description' => 'Whether to plug in the development-only FakeSerial printer.',
            'section' => 'Advanced settings',
            'visible' => false
        ],
        'fakeSerialBaudRate' => [
            'value' => 115200,
            'hint' => 'FakeSerial baud rate',
            'type' => DataType::INTEGER,
            'description' => 'The baud rate exposed by the development-only FakeSerial printer.',
            'section' => 'Advanced settings',
            'visible' => false
        ],
        'fakeSerialNode' => [
            'value' => 'FAKE0',
            'hint' => 'FakeSerial node',
            'type' => DataType::STRING,
            'description' => 'The virtual serial node name used by the development-only FakeSerial printer.',
            'section' => 'Advanced settings',
            'visible' => false,
            'writeable' => false
        ]

    ]
];
